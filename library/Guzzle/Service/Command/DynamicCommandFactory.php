<?php
/**
 * @package Guzzle PHP <http://www.guzzlephp.org>
 * @license See the LICENSE file that was distributed with this source code.
 */

namespace Guzzle\Service\Command;

use Guzzle\Common\Collection;
use Guzzle\Common\Injector;
use Guzzle\Common\Inspector;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\RequestFactory;
use Guzzle\Service\ApiCommand;
use Guzzle\Http\EntityBody;

/**
 * Build Guzzle commands based on a service document using dynamically created
 * commands to implement a service document's specifications.
 *
 * @author Michael Dowling <michael@guzzlephp.org>
 */
class DynamicCommandFactory extends AbstractCommandFactory
{
    /**
     * {@inheritdoc}
     */
    protected function createCommand(ApiCommand $command, Collection $args)
    {
        // Build the command based on the service doc and supplied arguments
        return new ClosureCommand(array_merge($args->getAll(), array(
            
            // Generate a dynamically created command using a closure to
            // prepare the command
            'closure' => function(ClosureCommand $that, ApiCommand $api) {

                // Validate the command with the config options
                Inspector::getInstance()->validateConfig($api->getArgs(), $that);

                // Get the path values and use the client config settings
                $pathValues = new Collection($that->getClient()->getConfig());
                $foundPath = false;
                foreach ($api->getArgs() as $name => $arg) {
                    if ($arg->get('location') == 'path') {
                        $pathValues->set($name, $arg->get('prepend') . $that->get($name) . $arg->get('append'));
                        $foundPath = true;
                    }
                }

                // Build a custom URL if there are path values
                if ($foundPath) {
                    $path = str_replace('//', '', Injector::inject($api->getPath(), $pathValues));
                    $url = Injector::inject($that->getClient()->getBaseUrl(false), $pathValues) . $path;
                } else {
                    $url = $that->getClient()->getBaseUrl() . $arg->get('path');
                }

                // Inject path and base_url values into the URL
                $request = RequestFactory::getInstance()->newRequest($api->getMethod(), $url);

                // Add arguments to the request using the location attribute
                foreach ($api->getArgs() as $name => $arg) {
                    
                    if ($that->get($name)) {

                        // Check that a location is set
                        $location = $arg->get('location') ?: 'query';

                        if ($location == 'path' || $location == 'data') {
                            continue;
                        }

                        if ($location) {

                            // Create the value based on prepend and append settings
                            $value = $arg->get('prepend') . $that->get($name) . $arg->get('append');

                            // Determine the location and key setting location[:key]
                            $parts = explode(':', $location);
                            $place = $parts[0];
                            
                            // If a key is specified (using location:key), use it
                            $key = isset($parts[1]) ? $parts[1] : $name;
                            
                            // Add the parameter to the request
                            switch ($place) {
                                case 'body':
                                    $request->setBody(EntityBody::factory($value));
                                    break;
                                case 'header':
                                    $request->setHeader($key, $value);
                                    break;
                                case 'query':
                                    $request->getQuery()->set($key, $value);
                                    break;
                            }
                        }
                    }
                }

                $that->setCanBatch($api->canBatch());

                return $request;
            },
            'closure_api' => $command
        )));
    }
}