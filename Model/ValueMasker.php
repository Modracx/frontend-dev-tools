<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

/**
 * Keeps personal data out of the query log.
 *
 * A storefront profiler records the statements a shopper's page ran, and the values bound to
 * them are whatever was being looked up: email addresses, names, addresses, session ids,
 * password hashes, payment tokens. Those get written to a database table and rendered into a
 * panel that any allowed developer can open. Masking them costs nothing and removes the
 * question entirely.
 *
 * Two rules, because either alone leaks. The bind key catches the obvious cases
 * (`:email`, `password`) and the value shape catches the ones with meaningless keys — bound
 * parameters are frequently just `?` or `:p0`, so judging on the name alone would mask
 * almost nothing.
 */
class ValueMasker
{
    private const SENSITIVE_KEY = '/pass|secret|key|token|salt|private|credential|licen[cs]e|signature'
        . '|cipher|hash|auth|session|cookie|email|mail|phone|telephone|postcode|zip|street|dob|tax|vat|iban|card/i';

    private const EMAIL = '/^[^@\s]+@[^@\s]+\.[^@\s]{2,}$/';

    /** Magento ciphertext, as written by the framework's encryptor. */
    private const CIPHERTEXT = '/^\d+:\d+:[A-Za-z0-9+\/=]{16,}$/';

    private const MASK = '••••••';

    public function maskBind(int|string $key, mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key)) {
            return self::MASK;
        }

        return $this->maskValue($value);
    }

    /**
     * Mask on the shape of the value alone.
     */
    public function maskValue(string $value): string
    {
        if (preg_match(self::EMAIL, $value)) {
            // Keep enough to recognise which account it was without disclosing it.
            [$local, $domain] = explode('@', $value, 2);

            return substr($local, 0, 1) . self::MASK . '@' . $domain;
        }

        if (preg_match(self::CIPHERTEXT, $value)) {
            return self::MASK;
        }

        // Long random-looking strings are tokens far more often than they are content.
        if (strlen($value) >= 32 && preg_match('/^[A-Za-z0-9+\/=_-]+$/', $value)) {
            return substr($value, 0, 4) . self::MASK;
        }

        return $value;
    }
}
