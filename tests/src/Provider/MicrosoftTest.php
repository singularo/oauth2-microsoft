<?php namespace Stevenmaguire\OAuth2\Client\Test\Provider;

use League\OAuth2\Client\Tool\QueryBuilderTrait;
use Mockery as m;

class MicrosoftTest extends \PHPUnit_Framework_TestCase
{
    use QueryBuilderTrait;

    protected $provider;

    protected function setUp()
    {
        $this->provider = new \Stevenmaguire\OAuth2\Client\Provider\Microsoft([
            'clientId' => 'mock_client_id',
            'clientSecret' => 'mock_secret',
            'redirectUri' => 'none',
        ]);
    }

    public function tearDown()
    {
        m::close();
        parent::tearDown();
    }

    public function testAuthorizationUrl()
    {
        $url = $this->provider->getAuthorizationUrl();
        $uri = parse_url($url);
        parse_str($uri['query'], $query);

        $this->assertArrayHasKey('client_id', $query);
        $this->assertArrayHasKey('redirect_uri', $query);
        $this->assertArrayHasKey('state', $query);
        $this->assertArrayHasKey('scope', $query);
        $this->assertArrayHasKey('response_type', $query);
        $this->assertArrayHasKey('approval_prompt', $query);
        $this->assertNotNull($this->provider->getState());
    }

    public function testScopes()
    {
        $scopeSeparator = ',';
        $options = ['scope' => [uniqid(), uniqid()]];
        $query = ['scope' => implode($scopeSeparator, $options['scope'])];
        $url = $this->provider->getAuthorizationUrl($options);
        $encodedScope = $this->buildQueryString($query);
        $this->assertContains($encodedScope, $url);
    }

    public function testGetAuthorizationUrl()
    {
        $url = $this->provider->getAuthorizationUrl();
        $uri = parse_url($url);

        $this->assertEquals('/oauth20_authorize.srf', $uri['path']);
    }

    public function testGetBaseAccessTokenUrl()
    {
        $params = [];

        $url = $this->provider->getBaseAccessTokenUrl($params);
        $uri = parse_url($url);

        $this->assertEquals('/oauth20_token.srf', $uri['path']);
    }

    public function testSettingAuthEndpoints()
    {
        $customAuthUrl = uniqid();
        $customTokenUrl = uniqid();
        $customResourceOwnerUrl = uniqid();
        $token = m::mock('League\OAuth2\Client\Token\AccessToken');

        $this->provider = new \Stevenmaguire\OAuth2\Client\Provider\Microsoft([
            'clientId' => 'mock_client_id',
            'clientSecret' => 'mock_secret',
            'redirectUri' => 'none',
            'urlAuthorize' => $customAuthUrl,
            'urlAccessToken' => $customTokenUrl,
            'urlResourceOwnerDetails' => $customResourceOwnerUrl
        ]);

        $authUrl = $this->provider->getAuthorizationUrl();
        $this->assertContains($customAuthUrl, $authUrl);
        $tokenUrl = $this->provider->getBaseAccessTokenUrl([]);
        $this->assertContains($customTokenUrl, $tokenUrl);
        $resourceOwnerUrl = $this->provider->getResourceOwnerDetailsUrl($token);
        $this->assertContains($customResourceOwnerUrl, $resourceOwnerUrl);

    }

    public function testGetAccessToken()
    {
        $response = m::mock('Psr\Http\Message\ResponseInterface');
        $response->shouldReceive('getBody')->andReturn('{"access_token":"mock_access_token","authentication_token":"","code":"","expires_in":3600,"refresh_token":"mock_refresh_token","scope":"","state":"","token_type":""}');
        $response->shouldReceive('getHeader')->andReturn(['content-type' => 'json']);

        $client = m::mock('GuzzleHttp\ClientInterface');
        $client->shouldReceive('send')->times(1)->andReturn($response);
        $this->provider->setHttpClient($client);

        $token = $this->provider->getAccessToken('authorization_code', ['code' => 'mock_authorization_code']);

        $this->assertEquals('mock_access_token', $token->getToken());
        $this->assertLessThanOrEqual(time() + 3600, $token->getExpires());
        $this->assertGreaterThanOrEqual(time(), $token->getExpires());
        $this->assertEquals('mock_refresh_token', $token->getRefreshToken());
        $this->assertNull($token->getResourceOwnerId());
    }

    public function testUserData()
    {
        $email = uniqid();
        $firstname = uniqid();
        $lastname = uniqid();
        $name = uniqid();
        $userId = rand(1000,9999);
        $userPrincipalName = uniqid();

        $postResponse = m::mock('Psr\Http\Message\ResponseInterface');
        $postResponse->shouldReceive('getBody')->andReturn('{"access_token":"mock_access_token","authentication_token":"","code":"","expires_in":3600,"refresh_token":"mock_refresh_token","scope":"","state":"","token_type":""}');
        $postResponse->shouldReceive('getHeader')->andReturn(['content-type' => 'json']);

        $userResponse = m::mock('Psr\Http\Message\ResponseInterface');
        $userResponse->shouldReceive('getBody')->andReturn('{"id": '.$userId.', "displayName": "'.$name.'", "givenName": "'.$firstname.'", "surname": "'.$lastname.'", "mail": "'.$email.'", "userPrincipalName": "'.$userPrincipalName.'"}');
        $userResponse->shouldReceive('getHeader')->andReturn(['content-type' => 'json']);

        $client = m::mock('GuzzleHttp\ClientInterface');
        $client->shouldReceive('send')
            ->times(2)
            ->andReturn($postResponse, $userResponse);
        $this->provider->setHttpClient($client);

        $token = $this->provider->getAccessToken('authorization_code', ['code' => 'mock_authorization_code']);
        $user = $this->provider->getResourceOwner($token);

        $this->assertEquals($email, $user->getEmail());
        $this->assertEquals($email, $user->toArray()['mail']);
        $this->assertEquals($userPrincipalName, $user->getPrincipalName());
        $this->assertEquals($userPrincipalName, $user->toArray()['userPrincipalName']);
        $this->assertEquals($firstname, $user->getFirstname());
        $this->assertEquals($firstname, $user->toArray()['first_name']);
        $this->assertEquals($firstname, $user->getGivenName());
        $this->assertEquals($firstname, $user->toArray()['givenName']);
        $this->assertEquals($lastname, $user->getLastname());
        $this->assertEquals($lastname, $user->toArray()['last_name']);
        $this->assertEquals($lastname, $user->getSurname());
        $this->assertEquals($lastname, $user->toArray()['surname']);
        $this->assertEquals($name, $user->getName());
        $this->assertEquals($name, $user->toArray()['name']);
        $this->assertEquals($name, $user->getDisplayName());
        $this->assertEquals($name, $user->toArray()['displayName']);
        $this->assertEquals($userId, $user->getId());
        $this->assertEquals($userId, $user->toArray()['id']);
        $this->assertNull($user->getUrls());
        $this->assertNull($user->toArray()['link']);
    }

    /**
     * @expectedException League\OAuth2\Client\Provider\Exception\IdentityProviderException
     **/
    public function testExceptionThrownWhenErrorObjectReceived()
    {
        $message = uniqid();

        $postResponse = m::mock('Psr\Http\Message\ResponseInterface');
        $postResponse->shouldReceive('getBody')->andReturn('{"error": {"code": "request_token_expired", "message": "'.$message.'"}}');
        $postResponse->shouldReceive('getHeader')->andReturn(['content-type' => 'json']);
        $postResponse->shouldReceive('getStatusCode')->andReturn(500);

        $client = m::mock('GuzzleHttp\ClientInterface');
        $client->shouldReceive('send')
            ->times(1)
            ->andReturn($postResponse);
        $this->provider->setHttpClient($client);

        $token = $this->provider->getAccessToken('authorization_code', ['code' => 'mock_authorization_code']);
    }

    public function testBearerAuthorizationHeader()
    {
        $token = uniqid();

        $headers = $this->provider->getHeaders($token);
        $this->assertTrue(!array_key_exists('Authorization', $headers));

        $this->provider->setAccessTokenType(\Stevenmaguire\OAuth2\Client\Provider\Microsoft::ACCESS_TOKEN_TYPE_BEARER);
        $headers = $this->provider->getHeaders($token);
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertEquals($headers['Authorization'], 'Bearer ' . $token);
    }

}
