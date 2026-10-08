<?php

/**
 * HTML layout of the quotation mail.
 *
 * Override it by copying this file to yourtheme/flux-quote/emails/quotation.php.
 *
 * Available in $args:
 * - content   string                  Body edited in Flux → Settings, placeholders replaced. Safe HTML.
 * - subject   string                  Mail subject.
 * - site_name string                  Site name.
 * - quotation Flux\Quote\Quotation    The quotation being sent.
 *
 * @package Flux_Quote
 */

defined('ABSPATH') || exit;
?>
<!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($args['subject']); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                    style="max-width:600px;background:#ffffff;border-radius:6px;">
                    <tr>
                        <td style="padding:32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">
                            <?php
                            // Built by MailTemplate: placeholders are escaped, the admin text went through wp_kses_post.
                            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            echo $args['content'];
                            ?>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#6b7280;">
                    <?php echo esc_html($args['site_name']); ?>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
