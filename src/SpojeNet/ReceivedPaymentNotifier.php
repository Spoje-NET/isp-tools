<?php

declare(strict_types=1);

/**
 * This file is part of the ISP Tools package
 *
 * https://github.com/Spoje-NET/isp-tools
 *
 * (c) Spoje.Net <https://spoje.net/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SpojeNet;

/**
 * Notifies the customer that their payment was received but awaits manual
 * matching by accounting.
 *
 * @author Vitex <info@vitexsoftware.cz>
 */
class ReceivedPaymentNotifier extends \Ease\Sand
{
    public function __construct(private \AbraFlexi\RO $payment)
    {
        $this->setObjectName('ReceivedPaymentNotifier');
    }

    /**
     * @param array<string, mixed> $paymentData payment fields (kod, sumCelkem, mena, varSym, datVyst)
     */
    public static function composeSubject(array $paymentData): string
    {
        return sprintf(
            _('Your payment %s was received'),
            \AbraFlexi\Functions::uncode((string) ($paymentData['kod'] ?? '')),
        );
    }

    /**
     * @param array<string, mixed> $paymentData payment fields (kod, sumCelkem, mena, varSym, datVyst)
     */
    public static function composeBody(array $paymentData): string
    {
        $body = [];
        $body[] = _('Dear customer,');
        $body[] = '';
        $body[] = sprintf(
            _('we have received your payment of %s %s (variable symbol %s) on %s.'),
            (string) ($paymentData['sumCelkem'] ?? ''),
            \AbraFlexi\Functions::uncode((string) ($paymentData['mena'] ?? '')),
            (string) ($paymentData['varSym'] ?? ''),
            substr((string) ($paymentData['datVyst'] ?? ''), 0, 10),
        );
        $body[] = '';
        $body[] = _('The payment could not be matched to an invoice automatically and awaits manual processing by our accounting department. No action is required on your side.');
        $body[] = '';
        $body[] = _('Thank you.');

        return implode("\n", $body);
    }

    /**
     * Notification recipient resolved from the payment's company (payer).
     *
     * Returns null when the payer is unknown — the common case for payments
     * that could not be matched automatically.
     */
    public function recipient(): ?string
    {
        $email = $this->payment->getEmail();

        if ($email !== '') {
            return $email;
        }

        $firma = trim((string) $this->payment->getDataValue('firma'));

        if ($firma === '' || $firma === 'code:') {
            return null;
        }

        $addresser = new \AbraFlexi\Adresar(\AbraFlexi\Functions::code(\AbraFlexi\Functions::uncode($firma)));
        $email = $addresser->getEmail();

        return $email !== '' ? $email : null;
    }

    /**
     * Send the payment-received notification.
     *
     * @param null|callable(string, string, string):\Ease\Mailer $mailerFactory custom mailer factory (tests)
     * @param null|string                                        $to            recipient override
     */
    public function send(?callable $mailerFactory = null, ?string $to = null): bool
    {
        $to ??= $this->recipient();

        if ($to === null || $to === '') {
            $this->addStatusMessage(sprintf(_('No email recipient for payment %s'), $this->payment->getRecordIdent()), 'warning');

            return false;
        }

        $paymentData = $this->payment->getData();
        $factory = $mailerFactory ?? static fn (string $to, string $subject, string $body): \Ease\Mailer => new \Ease\Mailer($to, $subject, $body);
        $mailer = $factory($to, self::composeSubject($paymentData), self::composeBody($paymentData));

        $sent = (bool) $mailer->send();

        $this->addStatusMessage(
            sprintf(_('Payment received notification for %s to %s'), $this->payment->getRecordIdent(), $to),
            $sent ? 'success' : 'error',
        );

        return $sent;
    }
}
