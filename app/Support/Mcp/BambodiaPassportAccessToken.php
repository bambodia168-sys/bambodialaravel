<?php

namespace App\Support\Mcp;

use DateTimeImmutable;
use Laravel\Passport\Bridge\AccessToken;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use League\OAuth2\Server\CryptKeyInterface;

/**
 * Passport uses the first JWT audience value as the OAuth client ID.
 * Retain that value and append our canonical MCP resource as a SECOND
 * audience only when the token explicitly grants mcp:use.
 *
 * Override toString() instead of modifying vendor code. League 9.2+ signs
 * the returned token using its configured Passport RSA private key.
 */
final class BambodiaPassportAccessToken extends AccessToken
{
    private CryptKeyInterface $bambodiaSigningKey;

    public function setPrivateKey(CryptKeyInterface $key): void
    {
        parent::setPrivateKey($key);
        $this->bambodiaSigningKey = $key;
    }

    public function toString(): string
    {
        $key = $this->bambodiaSigningKey;
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256(),
            InMemory::plainText($key->getKeyContents(), $key->getPassPhrase() ?? ''),
            InMemory::plainText('empty', 'empty')
        );

        $audiences = [(string) $this->getClient()->getIdentifier()];
        foreach ($this->getScopes() as $scope) {
            if ($scope->getIdentifier() === 'mcp:use') {
                $audiences[] = McpResource::uri();
                break;
            }
        }

        return $configuration->builder()
            ->permittedFor(...$audiences)
            ->issuedBy(McpResource::issuer())
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }
}
