/* global fluxQuoteSettings, ClipboardJS, tinymce */
(() => {
	const { __, sprintf } = wp.i18n;
	const settings = fluxQuoteSettings;

	const debounce = (callback, wait) => {
		let timer;
		return () => {
			clearTimeout(timer);
			timer = setTimeout(callback, wait);
		};
	};

	const previewPanel = (panel, render) => {
		const frame = panel.querySelector('.flux-quote-settings__frame');
		const notice = panel.querySelector('.notice');
		let controller;

		const showError = (message) => {
			notice.querySelector('p').textContent = message;
			notice.hidden = !message;
		};

		const run = () => {
			controller?.abort();
			controller = new AbortController();
			const { signal } = controller;

			frame.classList.add('is-loading');
			render(signal)
				.then(() => showError(''))
				.catch((error) => {
					if (!signal.aborted) {
						showError(error.message || __('The preview could not be loaded.', 'flux-quote'));
					}
				})
				.finally(() => {
					if (!signal.aborted) {
						frame.classList.remove('is-loading');
					}
				});
		};

		return { run, showError };
	};

	const initChips = () => {
		new ClipboardJS('.flux-quote-chip').on('success', (event) => {
			const chip = event.trigger;
			event.clearSelection();
			chip.classList.add('is-copied');
			setTimeout(() => chip.classList.remove('is-copied'), 1500);
			wp.a11y.speak(__('Copied to clipboard.', 'flux-quote'));
		});
	};

	const initMail = () => {
		const panel = document.querySelector('[data-preview="mail"]');
		const iframe = panel.querySelector('iframe');
		const subjectPreview = panel.querySelector('.flux-quote-settings__subject');
		const subject = document.getElementById('flux-quote-mail-subject');
		const textarea = document.getElementById('flux_quote_mail_body');
		const editor = () => window.tinymce?.get('flux_quote_mail_body');

		const body = () => (editor() && !editor().isHidden() ? editor().getContent() : textarea.value);

		const preview = previewPanel(panel, (signal) =>
			wp
				.apiFetch({
					path: settings.mailPath,
					method: 'POST',
					data: { subject: subject.value, body: body() },
					signal,
				})
				.then((response) => {
					subjectPreview.textContent = response.subject;
					iframe.srcdoc = response.html;
				})
		);
		const update = debounce(preview.run, 400);

		const fitHeight = () => {
			const body = iframe.contentDocument?.body;
			if (body) {
				iframe.style.minHeight = `${body.scrollHeight}px`;
			}
		};
		iframe.addEventListener('load', fitHeight);

		subject.addEventListener('input', update);
		textarea.addEventListener('input', update);
		const watch = (instance) => {
			if (instance.id === 'flux_quote_mail_body') {
				instance.on('input keyup change undo redo SetContent', update);
			}
		};
		if (window.tinymce) {
			tinymce.on('AddEditor', (event) => watch(event.editor));
			if (editor()) {
				watch(editor());
			}
		}

		preview.run();
	};

	const isObject = (value) => Boolean(value) && typeof value === 'object' && !Array.isArray(value);
	const notAnObject = (path) => sprintf(__('%s must be an object.', 'flux-quote'), path || __('The template', 'flux-quote'));
	const unknownField = (path) => sprintf(__('%s is not a known field.', 'flux-quote'), path);

	const blockErrors = (value, path) => {
		if (!Array.isArray(value)) {
			return [sprintf(__('%s must be a list.', 'flux-quote'), path)];
		}

		return value.flatMap((block, index) => {
			const blockPath = `${path}[${index}]`;
			if (!isObject(block)) {
				return [notAnObject(blockPath)];
			}

			const errors = settings.blockTypes.includes(block.type)
				? []
				: [
						sprintf(
							__('%1$s.type must be one of: %2$s.', 'flux-quote'),
							blockPath,
							settings.blockTypes.join(', ')
						),
					];
			Object.keys(block).forEach((key) => {
				if (!['type', 'data', 'props'].includes(key)) {
					errors.push(unknownField(`${blockPath}.${key}`));
				} else if (key !== 'type' && !isObject(block[key])) {
					errors.push(notAnObject(`${blockPath}.${key}`));
				}
			});
			return errors;
		});
	};

	const templateErrors = (value, schema, path = '') => {
		if (schema === true) {
			return typeof value === 'string' ? [] : [sprintf(__('%s must be text.', 'flux-quote'), path)];
		}
		if (schema === 'blocks') {
			return blockErrors(value, path);
		}
		if (!isObject(value)) {
			return [notAnObject(path)];
		}

		return Object.keys(value).flatMap((key) => {
			const itemPath = path ? `${path}.${key}` : key;
			return Object.hasOwn(schema, key) ? templateErrors(value[key], schema[key], itemPath) : [unknownField(itemPath)];
		});
	};

	const initPdf = () => {
		const panel = document.querySelector('[data-preview="pdf"]');
		const iframe = panel.querySelector('iframe');
		const openLink = panel.querySelector('[data-open]');
		const textarea = document.getElementById('flux-quote-pdf-template');
		const errorBox = document.querySelector('.flux-quote-settings__errors');
		const submit = document.getElementById('submit');
		const themeTextarea = document.getElementById('flux-quote-pdf-theme');
		const codemirror = settings.codeEditor
			? wp.codeEditor.initialize(textarea, settings.codeEditor).codemirror
			: null;
		const themeEditor = settings.cssEditor
			? wp.codeEditor.initialize(themeTextarea, settings.cssEditor).codemirror
			: null;
		const getValue = () => (codemirror ? codemirror.getValue() : textarea.value);
		const getTheme = () => (themeEditor ? themeEditor.getValue() : themeTextarea.value);
		let pdfUrl = '';

		const parse = () => {
			try {
				const value = JSON.parse(getValue());
				return { value, errors: templateErrors(value, settings.schema) };
			} catch (error) {
				return { value: null, errors: [sprintf(__('The template is not valid JSON: %s', 'flux-quote'), error.message)] };
			}
		};

		const showErrors = (errors) => {
			const list = errorBox.querySelector('ul');
			list.replaceChildren(
				...errors.map((message) => {
					const item = document.createElement('li');
					item.textContent = message;
					return item;
				})
			);
			errorBox.hidden = !errors.length;
			submit.disabled = errors.length > 0;
		};

		const preview = previewPanel(panel, (signal) => {
			const { value, errors } = parse();
			if (errors.length) {
				return Promise.resolve();
			}

			return wp
				.apiFetch({ path: settings.pdfPath, method: 'POST', data: { template: value, theme: getTheme() }, signal })
				.then((response) => {
					const bytes = Uint8Array.from(atob(response.pdf), (char) => char.charCodeAt(0));
					if (pdfUrl) {
						URL.revokeObjectURL(pdfUrl);
					}
					pdfUrl = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
					iframe.src = `${pdfUrl}#toolbar=0&navpanes=0&view=FitH`;
					openLink.href = pdfUrl;
					openLink.hidden = false;
				});
		});
		const debouncedPreview = debounce(preview.run, 800);
		const update = () => {
			showErrors(parse().errors);
			debouncedPreview();
		};

		if (codemirror) {
			codemirror.on('change', update);
		} else {
			textarea.addEventListener('input', update);
		}
		if (themeEditor) {
			themeEditor.on('change', debouncedPreview);
		} else {
			themeTextarea.addEventListener('input', debouncedPreview);
		}

		document.querySelector('.flux-quote-settings__reset').addEventListener('click', () => {
			if (codemirror) {
				codemirror.setValue(textarea.dataset.defaults);
			} else {
				textarea.value = textarea.dataset.defaults;
				update();
			}
		});

		showErrors(parse().errors);
		preview.run();
	};

	const initGeneral = () => {
		const input = document.getElementById('flux-company-logo');
		const image = document.querySelector('.flux-quote-settings__logo');
		let frame;

		const showLogo = () => {
			image.src = input.value;
			image.hidden = !input.value;
		};
		input.addEventListener('change', showLogo);

		document.querySelector('.flux-quote-settings__media').addEventListener('click', () => {
			frame ??= wp
				.media({
					title: __('Choose a logo', 'flux-quote'),
					library: { type: 'image' },
					multiple: false,
				})
				.on('select', () => {
					input.value = frame.state().get('selection').first().get('url');
					showLogo();
				});
			frame.open();
		});
	};

	document.addEventListener('DOMContentLoaded', () => {
		if (settings.tab === 'general') {
			initGeneral();
			return;
		}
		initChips();
		if (settings.tab === 'pdf') {
			initPdf();
		}
	});

	window.addEventListener('load', () => {
		if (settings.tab === 'mail') {
			initMail();
		}
	});
})();
