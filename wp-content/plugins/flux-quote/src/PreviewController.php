<?php

declare(strict_types=1);

namespace Flux\Quote;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class PreviewController
{
    public function __construct(private Adapter $adapter, private Repository $repository)
    {
    }

    public function register_routes(): void
    {
        register_rest_route($this->adapter->rest_namespace(), '/preview/mail', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'mail'],
            'permission_callback' => [$this, 'can_preview'],
            'args' => [
                'subject' => ['type' => 'string', 'default' => ''],
                'body' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route($this->adapter->rest_namespace(), '/preview/pdf', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'pdf'],
            'permission_callback' => [$this, 'can_preview'],
            'args' => [
                'template' => ['type' => 'object', 'required' => true],
                'theme' => ['type' => 'string', 'default' => ''],
            ],
        ]);
    }

    public function can_preview(): bool
    {
        return current_user_can('manage_options');
    }

    public function mail(WP_REST_Request $request): array
    {
        $template = new MailTemplate(MailTemplate::clean($request->get_params()));
        $quotation = $this->sample();

        return [
            'subject' => $template->subject($quotation),
            'html' => $template->message($quotation),
        ];
    }

    public function pdf(WP_REST_Request $request): array|WP_Error
    {
        $input = $request['template'];
        if (is_array($input)) {
            $input['theme'] = $request['theme'];
        }
        $template = new PdfTemplate();
        $errors = $template->errors($input);
        if ($errors) {
            return new WP_Error('invalid_template', implode(' ', $errors), ['status' => 400]);
        }

        $client = new PdfClient(new PdfTemplate($template->clean($input)));
        $pdf = $client->render($this->adapter->pdf_payload($this->sample()));
        if (is_wp_error($pdf)) {
            return new WP_Error('pdf_failed', $pdf->get_error_message(), ['status' => 502]);
        }

        return ['pdf' => base64_encode($pdf)];
    }

    private function sample(): Quotation
    {
        $quotation = $this->repository->latest_quotation();
        if ($quotation) {
            return $quotation;
        }

        $contact = ['firstName' => 'Jane', 'lastName' => 'Doe', 'email' => 'jane.doe@example.com'];

        return new Quotation(
            post_id: 0,
            public_id: '0123456789ab',
            private_id: '',
            title: __('Sample quotation', 'flux-quote'),
            configuration: [],
            contact: $contact,
            document: [
                'header' => [
                    'headerInfo' => '0123456789ab',
                    'name' => 'Jane Doe',
                    'email' => $contact['email'],
                ],
                'blocks' => [
                    [
                        'type' => 'table',
                        'data' => [
                            'title' => __('Sample product', 'flux-quote'),
                            'header' => [__('Description', 'flux-quote'), __('Quantity', 'flux-quote')],
                            'items' => [[__('Sample item', 'flux-quote'), 1]],
                        ],
                    ],
                ],
            ],
            pdf_url: null,
            pdf_status: PdfStatus::Pending,
            mail_status: MailStatus::Pending,
            mail_error: null,
            status: QuoteStatus::Draft,
            created_at: current_time('mysql'),
        );
    }
}
