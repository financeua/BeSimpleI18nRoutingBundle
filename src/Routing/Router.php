<?php

namespace BeSimple\I18nRoutingBundle\Routing;

use BeSimple\I18nRoutingBundle\Routing\RouteGenerator\NameInflector\PostfixInflector;
use BeSimple\I18nRoutingBundle\Routing\RouteGenerator\NameInflector\RouteNameInflectorInterface;
use BeSimple\I18nRoutingBundle\Routing\Translator\AttributeTranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

class Router implements RouterInterface, RequestMatcherInterface
{
    /**
     * @var AttributeTranslatorInterface
     */
    protected $translator;

    /**
     * @var RequestMatcherInterface|RouterInterface
     */
    protected $router;

    /**
     * The locale to use when neither the parameters nor the request context
     * indicate the locale to use.
     *
     * @var string
     */
    protected $defaultLocale;

    /**
     * @var RouteNameInflectorInterface
     */
    private $routeNameInflector;

    /**
     * Constructor
     *
     * @param RouterInterface                   $router The router instance. Instead of RouterInterface, may also
     *                                                  be RequestMatcherInterface and UrlGeneratorInterface
     * @param AttributeTranslatorInterface|null $translator
     * @param string                            $defaultLocale
     * @param RouteNameInflectorInterface|null  $routeNameInflector
     * @throws \InvalidArgumentException
     */
    public function __construct($router, AttributeTranslatorInterface $translator = null, $defaultLocale = null, RouteNameInflectorInterface $routeNameInflector = null)
    {
        if (!$router instanceof RouterInterface
            && !($router instanceof RequestMatcherInterface && $router instanceof UrlGeneratorInterface)
        ) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid router.', get_class($router)));
        }

        $this->router = $router;
        $this->translator = $translator;
        $this->defaultLocale = $defaultLocale;
        $this->routeNameInflector = $routeNameInflector ?: new PostfixInflector();
    }

    /**
     * Generates a URL from the given parameters.
     *
     * @param string   $name       The name of the route
     * @param array    $parameters An array of parameters
     * @param bool|int $referenceType The type of reference to be generated (one of the constants)
     *
     * @return string The generated URL
     *
     * @throws \InvalidArgumentException When the route doesn't exists
     */
    public function generate($name, $parameters = array(), $referenceType = self::ABSOLUTE_PATH)
    {
        if (isset($parameters['locale']) || isset($parameters['translate'])) {
            $locale = $this->getLocale($parameters);

            if (isset($parameters['locale'])) {
                unset($parameters['locale']);
            }

            if (null === $locale) {
                throw new MissingMandatoryParametersException('The locale must be available when using the "translate" option.');
            }

            if (isset($parameters['translate'])) {
                if (null !== $this->translator) {
                    foreach ((array) $parameters['translate'] as $translateAttribute) {
                        $parameters[$translateAttribute] = $this->translator->reverseTranslate(
                            $name,
                            $locale,
                            $translateAttribute,
                            $parameters[$translateAttribute]
                        );
                    }
                }
                unset($parameters['translate']);
            }

            return $this->generateI18n($name, $locale, $parameters, $referenceType);
        }

        try {
            return $this->router->generate($name, $parameters, $referenceType);
        } catch (RouteNotFoundException $e) {
            $locale = $this->getLocale($parameters);
            if (null !== $locale) {
                // at this point here we would never have $parameters['translate'] due to condition before
                return $this->generateI18n($name, $locale, $parameters, $referenceType);
            }

            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function match($pathinfo)
    {
        return $this->filterMatch($this->router->match($pathinfo));
    }

    /**
     * {@inheritdoc}
     */
    public function matchRequest(Request $request)
    {
        return $this->filterMatch($this->router->matchRequest($request));
    }

    /**
     * {@inheritdoc}
     */
    public function getRouteCollection()
    {
        return $this->router->getRouteCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function setContext(RequestContext $context)
    {
        $this->router->setContext($context);
    }

    /**
     * {@inheritdoc}
     */
    public function getContext()
    {
        return $this->router->getContext();
    }

    /**
     * Overwrite the locale to be used by default if the current locale could
     * not be found when building the route
     *
     * @param string $locale
     */
    public function setDefaultLocale($locale)
    {
        $this->defaultLocale = $locale;
    }

    /**
     * Filter Match
     *
     * @param array $match
     * @return array
     */
    protected function filterMatch($match) {
        // if a _locale parameter isset remove the .locale suffix that is appended to each route in I18nRoute
       if (!empty($match['_locale']) && preg_match('#^(.+)\.'.preg_quote($match['_locale'], '#').'+$#', $match['_route'], $route)) {
           $match['_route'] = $route[1];

           // now also check if we want to translate parameters:
           if (null !== $this->translator && isset($match['_translate'])) {
               foreach ((array) $match['_translate'] as $attribute) {
                   $match[$attribute] = $this->translator->translate(
                       $match['_route'],
                       $match['_locale'],
                       $attribute,
                       $match[$attribute]
                   );
               }
           }
       }

       return $match;
    }

    /**
     * Generates a I18N URL from the given parameter
     *
     * @param string   $name       The name of the I18N route
     * @param string   $locale     The locale of the I18N route
     * @param array    $parameters An array of parameters
     * @param bool|int $referenceType The type of reference to be generated (one of the constants)
     *
     * @return string The generated URL
     *
     * @throws RouteNotFoundException When the route doesn't exists
     */
    protected function generateI18n($name, $locale, $parameters, $referenceType = self::ABSOLUTE_PATH)
    {
        try {
            return $this->router->generate(
                $this->routeNameInflector->inflect($name, $locale),
                $parameters,
                $referenceType
            );
        } catch (RouteNotFoundException $e) {
            throw new RouteNotFoundException(sprintf('I18nRoute "%s" (%s) does not exist.', $name, $locale));
        }
    }

    /**
     * Determine the locale to be used with this request
     *
     * @param array $parameters the parameters determined by the route
     *
     * @return string
     */
    protected function getLocale($parameters)
    {
        if (isset($parameters['locale'])) {
            return $parameters['locale'];
        }

        if ($this->getContext()->hasParameter('_locale')) {
            return $this->getContext()->getParameter('_locale');
        }

        return $this->defaultLocale;
    }
}
