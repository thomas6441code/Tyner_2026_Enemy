<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Answers whether an email address belongs to a domain that can actually receive mail.
 *
 * This is a DNS check, not a mailbox check: it proves "gmail.com accepts mail", never
 * "this person exists at gmail.com". Probing an individual mailbox needs an SMTP RCPT TO
 * conversation, which most providers (Gmail included) answer with a catch-all accept and
 * which routinely gets the prober's IP blocklisted — so the honest, useful line is drawn
 * at the domain. That still rejects the failure this system actually cares about: a typo'd
 * or invented domain, where an approved applicant's activation link would silently bounce
 * and the account could never be created.
 */
class MailDomainResolver
{
    /**
     * Whether mail sent to this address stands a chance of being delivered.
     */
    public function acceptsMail(string $email): bool
    {
        $domain = Str::of($email)->afterLast('@')->trim()->lower()->toString();

        if ($domain === '' || ! str_contains($email, '@')) {
            return false;
        }

        $domain = $this->toAscii($domain);

        // Cached because this sits on an anonymous public endpoint: without it, a form
        // resubmission loop turns into a DNS query loop.
        return Cache::remember(
            'mail-domain:'.$domain,
            now()->addDay(),
            fn (): bool => $this->lookup($domain),
        );
    }

    /**
     * Live DNS lookup for a single domain.
     */
    protected function lookup(string $domain): bool
    {
        $mx = @dns_get_record($domain, DNS_MX) ?: [];

        if ($mx !== []) {
            foreach ($mx as $record) {
                // RFC 7505 "null MX": a single "." target is the domain explicitly
                // publishing that it accepts no mail. example.com does exactly this.
                if (rtrim((string) ($record['target'] ?? ''), '.') !== '') {
                    return true;
                }
            }

            return false;
        }

        // No MX at all — RFC 5321 §5.1 permits falling back to the address record.
        return @checkdnsrr($domain, 'A') || @checkdnsrr($domain, 'AAAA');
    }

    /**
     * Punycode an internationalised domain so the resolver sees what DNS stores.
     */
    protected function toAscii(string $domain): string
    {
        if (! function_exists('idn_to_ascii')) {
            return $domain;
        }

        return idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain;
    }
}
