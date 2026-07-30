<?php
declare(strict_types=1);

namespace Modracx\FrontendDevTools\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Decides whether this visitor may see the toolbar.
 *
 * The storefront is public, which is the whole difference between this module and the admin
 * one: there, being signed in to the backend is already an answer. Here three independent
 * things must agree before a single collector runs —
 *
 *   1. the module is enabled, and we are not in production mode (unless explicitly allowed);
 *   2. the client address is in the allow list;
 *   3. the browser holds a cookie signed with this installation's crypt key.
 *
 * Any one of them failing means the request is indistinguishable from an ordinary shopper's:
 * nothing is timed, nothing is stored, nothing is injected.
 */
class AccessGate
{
    public const COOKIE_NAME = 'mdx_fdt';
    public const HINTS_COOKIE = 'mdx_fdt_hints';

    private ?bool $allowed = null;

    public function __construct(
        private readonly Config $config,
        private readonly State $appState,
        private readonly RemoteAddress $remoteAddress,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->allowed ??= $this->evaluate();
    }

    /**
     * Template hints for this browser only.
     *
     * Magento's own dev/debug/template_hints_storefront is store scoped, so switching it on
     * to look at one block turns it on for every visitor of that store. This one rides on a
     * cookie, and is still behind the gate above.
     */
    public function wantsTemplateHints(): bool
    {
        return $this->isAllowed() && $this->cookieManager->getCookie(self::HINTS_COOKIE) !== null;
    }

    public function wantsBlockHints(): bool
    {
        return $this->wantsTemplateHints() && $this->cookieManager->getCookie(self::HINTS_COOKIE) === 'blocks';
    }

    /**
     * The value the access cookie must carry, derived from the installation's crypt key.
     *
     * Deriving rather than storing means the token is never written to the database, cannot
     * be read out of a config dump, and changes the moment the encryption key is rotated.
     */
    public function expectedToken(): string
    {
        $key = (string)$this->deploymentConfig->get('crypt/key');

        return substr(hash_hmac('sha256', 'modracx-frontend-dev-tools', $key), 0, 32);
    }

    public function tokenMatches(string $candidate): bool
    {
        return hash_equals($this->expectedToken(), $candidate);
    }

    public function grant(): void
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setPath('/')
            ->setDuration(86400 * 30)
            ->setHttpOnly(false)
            ->setSameSite('Lax');

        $this->cookieManager->setPublicCookie(self::COOKIE_NAME, $this->expectedToken(), $metadata);
        $this->allowed = null;
    }

    public function revoke(): void
    {
        $metadata = $this->cookieMetadataFactory->createCookieMetadata()->setPath('/');
        $this->cookieManager->deleteCookie(self::COOKIE_NAME, $metadata);
        $this->cookieManager->deleteCookie(self::HINTS_COOKIE, $metadata);
        $this->allowed = false;
    }

    public function setTemplateHints(string $mode): void
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setPath('/')
            ->setDuration(86400)
            ->setHttpOnly(false)
            ->setSameSite('Lax');

        if ($mode === 'off') {
            $this->cookieManager->deleteCookie(
                self::HINTS_COOKIE,
                $this->cookieMetadataFactory->createCookieMetadata()->setPath('/')
            );

            return;
        }

        $this->cookieManager->setPublicCookie(self::HINTS_COOKIE, $mode, $metadata);
    }

    /**
     * Whether the area is known yet.
     *
     * Some of the collectors are wired globally rather than per area, because the objects
     * they wrap — the application itself, the database logger — are built during bootstrap,
     * before area configuration is loaded and therefore before an area-scoped plugin would
     * exist at all. Those collectors ask this first and simply do not record until the
     * request has decided what it is; the handful of configuration reads that happen in that
     * window are not what anybody is profiling.
     */
    public function areaResolved(): bool
    {
        try {
            return $this->appState->getAreaCode() !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function evaluate(): bool
    {
        if (!$this->config->isEnabled() || !$this->isFrontendArea()) {
            return false;
        }

        if ($this->mode() === State::MODE_PRODUCTION && !$this->config->allowsProduction()) {
            return false;
        }

        if (!$this->ipAllowed((string)$this->remoteAddress->getRemoteAddress())) {
            return false;
        }

        if (!$this->config->requiresCookie()) {
            return true;
        }

        $cookie = $this->cookieManager->getCookie(self::COOKIE_NAME);

        return is_string($cookie) && $this->tokenMatches($cookie);
    }

    private function isFrontendArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable) {
            return false;
        }
    }

    private function mode(): string
    {
        try {
            return $this->appState->getMode();
        } catch (\Throwable) {
            // An installation too broken to report its own mode is treated as production.
            return State::MODE_PRODUCTION;
        }
    }

    private function ipAllowed(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        foreach ($this->config->allowedIps() as $rule) {
            if ($rule === $ip) {
                return true;
            }

            if (str_contains($rule, '/') && $this->inCidr($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * CIDR containment for both address families.
     *
     * Both sides are compared as raw packed bytes, so one implementation covers IPv4 and
     * IPv6 without any of the usual ip2long() truncation.
     */
    private function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        // Validated before conversion rather than silencing the warning inet_pton emits: a
        // malformed allow-list entry is a configuration mistake worth failing closed on, not
        // worth hiding.
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        $bits = (int)$bits;

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;

        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }
}
