<?php
/**
 * HaphazardTestCase.php
 *
 * @copyright 2013 (c) Ink Applications LLC. All rights reserved.
 * @license MIT <http://opensource.org/licenses/MIT>
 */

namespace Ink\Haphazard\TestCase;

use Ink\Haphazard\HttpMethods;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\SecurityContext;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Haphazard Test Case
 *
 * This is a functional test-case for quick, and dirty testing of pages.
 * It provides a way to assert that page requests simply load with the correct
 * status codes as expected.
 *
 * @author Maxwell Vandervelde <Max@MaxVandervelde.com>
 */
abstract class HaphazardTestCase extends WebTestCase
{
    /**
     * @var Client The browser simulator.
     */
    private $client;

    /**
     * @var string The User provider used for the application firewall
     */
    private $providerClass;

    /**
     * @var string The name of the application firewall
     */
    private $firewall;

    /**
     * Constructor
     *
     * @param string $provider The User provider used for the application
     *     firewall. (Default: fos_user.user_provider.username)
     * @param string $firewall The name of the firewall. (Default: secured_area)
     */
    public function __construct(
        $name = null,
        array $data = array(),
        $dataName = '',
        $provider = 'fos_user.user_provider.username',
        $firewall = 'secured_area'
    ) {
        parent::__construct($name, $data, $dataName);
        $this->providerClass = $provider;
        $this->firewall = $firewall;
    }

    /**
     * Assert Get
     *
     * Asserts the response code of a web request matches what was expected.
     *
     * @param string $route The name of the controller route to test. such as
     *     'default' or 'index'.
     * @param array $parameters (optional) Parameters to pass into the route
     *     when resolving by name. The route parameters should be in
     *     key => value format.
     *
     *     example:
     *
     *     ~~~
     *         array(
     *             'product_id' => 1,
     *             'page' => 3,
     *         );
     *     ~~~
     * @param int $status (optional) [Default: 200] The Response code that was
     *     expected back from the request. Typically 200 or 403.
     */
    protected function assertGet($route, $parameters = array(), $status = 200)
    {
        $url = $this->getRouter()->generate($route, $parameters);

        $this->getClient()->request('GET', $url);

        $responseStatus = $this->getClient()->getResponse()->getStatusCode();
        $this->assertSame($status, $responseStatus);
    }

    /**
     * Assert that a request matches the expected response code
     *
     * @param string $method The HTTP request method.
     * @param string $routeName The Symfony named route.
     * @param array $routeParameters Parameters that will be added to the route
     *     url.  Url will be built with the router generate method.
     * @param array $postParameters Parameters to pass as $_POST.
     * @param int $expectedStatus The expected response code after performing a
     *     request.
     * @param array $files An array of files
     * @param array $server The server parameters (HTTP headers are referenced
     *     with a HTTP_ prefix as PHP does)
     * @param null $content The raw body data
     * @param bool $changeHistory Whether to update the history or not (only
     *     used internally for back(), forward(), and reload())
     */
    protected function assertRequest(
        $method,
        $routeName,
        array $routeParameters = array(),
        array $postParameters = array(),
        $expectedStatus = 200,
        array $files = array(),
        array $server = array(),
        $content = null,
        $changeHistory = true
    ) {
        $method = HttpMethods::valueOf($method);
        $url = $this->getRouter()->generate($routeName, $routeParameters);

        $this->getClient()->request($method, $url, $postParameters, $files, $server, $content, $changeHistory);

        $responseStatus = $this->getClient()->getResponse()->getStatusCode();
        $this->assertSame($expectedStatus, $responseStatus);
    }

    /**
     * Assert form submissions
     *
     * @param Crawler $crawler Symfony DomCrawler which makes traversing the DOM
     *     easier.
     * @param array $parameters (optional) An array of form fields.  The value
     *     of each element will be set to the appropriate form field.
     * @param int $status (optional) The expected response code.  Defaults to
     *     302 for form submissions.
     * @param string $selectButton (optional) The string that will be searched
     *     for to define the select button.
     */
    protected function assertSubmit(
        Crawler $crawler,
        array $parameters = array(),
        $status = 302,
        $selectButton = 'submit'
    ) {
        $this->mockCsrfProvider();

        // get the form and submit
        $form = $crawler->selectButton($selectButton)->form($parameters);
        $this->getClient()->submit($form);

        $responseStatus = $this->getClient()->getResponse()->getStatusCode();
        $this->assertSame($status, $responseStatus);
    }

    /**
     * Login
     *
     * Fakes a logged in user with the application's security context.
     *
     * WARNING: Using this method will force a refresh of the client!
     *
     * @param UserInterface $user The user to login as.
     */
    protected function login(UserInterface $user)
    {
        $this->refreshClient();
        $this->setupMockProvider($user);
        $this->setupSessionCookie($user);
    }

    /**
     * Refresh Client
     *
     * Creates a new Client object context to use.
     */
    protected function refreshClient()
    {
        $this->client = static::createClient();
    }

    /**
     * Get Client
     *
     * Gets the HTTP simulator client, creating it if it does not currently
     * exist.
     *
     * @param bool $refresh Whether to force a new client to be created
     * @return Client The HTTP simulator
     */
    final protected function getClient($refresh = false)
    {
        if (null === $this->client || true === $refresh) {
            $this->refreshClient();
        }

        return $this->client;
    }

    /**
     * Get Container
     *
     * @return ContainerInterface Symfony's DI container
     */
    final protected function getContainer()
    {
        return $this->getClient()->getContainer();
    }

    /**
     * Get Router
     *
     * This method uses the HTTP client to get the router service.
     *
     * @return Router The Symfony Routing service
     */
    final protected function getRouter()
    {
        $container = $this->getContainer();
        $router = $container->get('router');

        return $router;
    }

    /**
     * Get Security Context
     *
     * @return SecurityContext
     */
    private function getSecurityContext()
    {
        return $this->getContainer()->get('security.context');
    }

    /**
     * Setup Session Cookie
     *
     * @param UserInterface $user The user being logged in as
     */
    private function setupSessionCookie(UserInterface $user)
    {
        $session = $this->getContainer()->get('session');
        $token = new UsernamePasswordToken(
            $user,
            null,
            $this->firewall,
            $user->getRoles()
        );
        $session->set('_security_' . $this->firewall, serialize($token));
        $session->save();

        $cookie = new Cookie($session->getName(), $session->getId());
        $this->client->getCookieJar()->set($cookie);
    }

    /**
     * Setup Mock Provider
     *
     * Injects a mock Security User Provider into the DI container based on the
     * service key provided on construction.
     *
     * @param UserInterface $user The user being logged in as
     */
    private function setupMockProvider(UserInterface $user)
    {
        $mockProvider = $this->getMock('Symfony\Component\Security\Core\User\UserProviderInterface');

        $mockProvider->expects($this->any())
            ->method('refreshUser')
            ->will(
                $this->returnValue($user)
            );
        $mockProvider->expects($this->any())
            ->method('loadUserByUsername')
            ->with($this->equalTo($user->getUsername()))
            ->will(
                $this->returnValue($user)
            );
        $mockProvider->expects($this->any())
            ->method('supportsClass')
            ->will(
                $this->returnValue(true)
            );

        $this->getContainer()->set($this->providerClass, $mockProvider);
    }

    /**
     * Setup Mock Csrf Provider
     *
     * Override the container's csrf provider to always return true.  This
     * is necessary because after the first request is made (to get the
     * crawler), the user is logged out and must log in again.  This will
     * invalidate the csrf token that was previously generated for the form.
     */
    private function mockCsrfProvider()
    {
        $mockCsrfProvider = $this->getMockBuilder('Symfony\Component\Form\Extension\Csrf\CsrfProvider\DefaultCsrfProvider')
            ->disableOriginalConstructor()
            ->getMock();

        $mockCsrfProvider->expects($this->any())
            ->method('isCsrfTokenValid')
            ->will($this->returnValue(true));

        $this->getContainer()->set('form.csrf_provider', $mockCsrfProvider);
    }
}
