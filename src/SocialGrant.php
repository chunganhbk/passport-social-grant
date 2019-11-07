<?php

namespace Adaojunior\Passport;

use DateInterval;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Bridge\User as UserEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

class SocialGrant extends AbstractGrant
{
    private $resolver;

    public function __construct(SocialGrantAccessTokenResolver $resolver, RefreshTokenRepositoryInterface $refreshTokenRepository)
    {
        $this->resolver = $resolver;
        $this->setRefreshTokenRepository($refreshTokenRepository);
        $this->refreshTokenTTL = new DateInterval('P1M');
    }

    public function getIdentifier()
    {
        return 'social';
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL
    ) {

        // Validate request
        $client = $this->validateClient($request);

        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request), $this->defaultScope);

        $user = $this->validateUser($request, $client);

        // Finalize the requested scopes
        $scopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $user->getIdentifier());

        // Issue and persist new access token
        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $user->getIdentifier(), $scopes);
        $this->getEmitter()->emit(new RequestEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request));
        $responseType->setAccessToken($accessToken);

        // Issue and persist new refresh token if given
        $refreshToken = $this->issueRefreshToken($accessToken);

        if ($refreshToken !== null) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request));
            $responseType->setRefreshToken($refreshToken);
        }

        return $responseType;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ClientEntityInterface $client
     * @return UserEntityInterface
     * @throws OAuthServerException
     */
    protected function validateUser(ServerRequestInterface $request, ClientEntityInterface $client)
    {
        $user = $this->resolver->resolve(
            $this->getParameter('network', $request),
            $this->getParameter('access_token', $request),
            $client
        );

        if ($user instanceof Authenticatable) {
            $user = new UserEntity($user->getAuthIdentifier());
        }

        if ($user instanceof UserEntityInterface === false) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));
            throw OAuthServerException::invalidCredentials();
        }

        return $user;
    }

    protected function getParameter($param, ServerRequestInterface $request, $required = true)
    {
        $value = $this->getRequestParameter($param, $request);

        if (is_null($value) && $required) {
            throw OAuthServerException::invalidRequest($param);
        }

        return $value;
    }
}
