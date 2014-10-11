<?php namespace Hamedbahrami\EnhancedRouter\Routing;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router as IlluminateRouter;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Illuminate\Routing\RouteCollection;

class Router extends IlluminateRouter {

	/**
	 * Array of route groups.
	 * 
	 * @var array
	 */
	protected $routeGroups = array();
	
	/**
	 * The HTTP verb to filter bindings.
	 * 
	 * @var array
	 */
	protected $httpVerbFilters = array();

	/**
	 * Tie a registered middleware to an HTTP verb or verbs.
	 * 
	 * @param  string|array  $verbs
	 * @param  string|array  $names
	 * @return void
	 */
	public function on($verbs, $names)
	{
		foreach ((array) $verbs as $verb)
		{
			foreach ((array) $names as $name)
			{
				$this->httpVerbFilters[strtolower($verb)][] = $name;
			}
		}
	}

	/**
	 * Add a route to the underlying route collection.
	 *
	 * @param  array|string  $methods
	 * @param  string  $uri
	 * @param  \Closure|array|string  $action
	 * @return \Illuminate\Routing\Route
	 */
	protected function addRoute($methods, $uri, $action)
	{
		return $this->routes->add($this->createRoute($methods, $uri, $action));
	}

	/**
	 * Create a route group with shared attributes. Overloading this method allows
	 * developers to chain requirements and filters to all routes within the
	 * group.
	 * 
	 * @param  array  $attributes
	 * @param  Closure  $callback
	 * @return \Hamedbahrami\EnhancedRouter\Routing\RouteGroup
	 */
	public function group(array $attributes, Closure $callback)
	{
		$original = clone $this->routes;

		parent::group($attributes, $callback);

		// We can now get the routes that were added in this group by comparing the
		// keys of the original routes and of the routes we have after the group
		// callback was fired.
		$routes = array_diff_key($this->routes->getRoutes(), $original->getRoutes());

		// With a brand new route collection we'll spin through all of the routes
		// defined within our group and add them to the collection.
		$collection = new RouteCollection;

		foreach ($routes as $key => $route)
		{
			$collection->add($route);
		}

		// Reset the routes on the router to the original collection of routes that
		// we cloned earlier. This way we don't end up with any double ups when
		// the groups are merged later on.
		// $this->routes = $original;

		return $this->routeGroups[] = new RouteGroup($collection, count($this->groupStack));
	}

	/**
	 * Merge route groups into the core route collection.
	 * 
	 * @return void
	 */
	protected function mergeRouteGroups()
	{ 
		$routes = $this->routes->getRoutes();

		foreach ($this->routeGroups as $key => $group)
		{
			// Spin through every route and merge the group filters onto the route.
			foreach ($group->getRoutes() as $route)
			{
				// If the group is nested within other groups we need to spin over those
				// groups and merge in those filters as well. This allows a filter
				// applied to an outer group be used on all routes within that
				// group, even if they are within other groups.
				if ($group->getGroupDepth() > 0)
				{
					for ($i = count($this->routeGroups) - $group->getGroupDepth(); $i < count($this->routeGroups); ++$i)
					{
						$this->mergeGroupFilters($route, $this->routeGroups[$i]);
					}
				}

				// After any outer group filters have been applied we can merge the
				// filters from the immediate parent group of the route. This is
				// so that outer group filters are run first, since they are
				// technically the first filters that are applied.
				$this->mergeGroupFilters($route, $group);
			}

			$routes = array_merge($routes, $group->getRoutes());
		}

		$this->routes = new RouteCollection;

		foreach ($routes as $name => $route)
		{
			$this->routes->add($route);
		}

		$this->routeGroups = array();
	}

	/**
	 * Merge a groups filters onto a route.
	 * 
	 * @param  \Illuminate\Routing\Route  $route
	 * @param  \Hamedbahrami\EnhancedRouter\Routing\RouteGroup  $group
	 * @return void
	 */
	protected function mergeGroupFilters($route, $group)
	{
		$before = array_unique(array_merge(array_keys($route->beforeFilters()), $group->beforeFilters()));
		$before = implode("|", $before);

		$route->before($before);

		$after = array_unique(array_merge(array_keys($route->afterFilters()), $group->afterFilters()));
		$after = implode("|", $after);

		$route->after($after);
	}

	/**
	 * Get the response for a given request.
	 * Overloaded so that we can merge route groups.
	 * 
	 * @param  \Symfony\Component\HttpFoundation\Request  $request
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function dispatch(Request $request){
		$this->mergeRouteGroups();

		return parent::dispatch($request);
	}
}