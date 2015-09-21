<?php
// Google+ extension for Bolt

namespace Bolt\Extension\DanielKulbe\GooglePlus;

use Guzzle\Http\StaticClient as Client;
use Guzzle\Http\Url as Url;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Response;

class Extension extends \Bolt\BaseExtension
{
    /**
     * Extension name
     *
     * @var string
     */
    const NAME = 'Google+';

    /**
     * API base URL
     *
     * @var string
     */
    const API = 'https://www.googleapis.com/plus/v1/people';

    /**
     * API cUrl request options
     *
     * @var array
     */
    private static $curl_options = array(
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.2; WOW64) AppleWebKit/537.31 (KHTML, like Gecko) Chrome/26.0.1410.64 Safari/537.31',
        CURLOPT_AUTOREFERER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => 0,
        CURLOPT_SSL_VERIFYHOST => 0
    );

    /**
     * Runtime, for to compare with local files time.
     *
     * @var int
     */
    private $runtime;

    /**
     * Allowed file formats for saveFile()
     *
     * @see http://php.net/manual/en/image.constants.php
     * @var array
     */
    private static $fileFormats = array(
        '.jpg' => IMAGETYPE_JPEG,
        '.png' => IMAGETYPE_PNG,
        '.gif' => IMAGETYPE_GIF,
    );


    /**
     * Get the extension's human readable name
     *
     * @return string
     */
    public function getName()
    {
        return Extension::NAME;
    }


    /**
     * Add Twig settings in 'frontend' environment
     *
     * @return void
     */
    public function initialize()
    {
        // Add Extension template path
        $this->app['twig.loader.filesystem']->addPath(__DIR__ . '/assets');

        // Add theme template path (makes sure it is also available in async environment)
        $this->app['twig.loader.filesystem']->addPath($this->app['paths']['themepath']);

        // Set up the routes for the widgets
        $this->app->match("/googleplus/{type}", array($this, 'renderWidget'));

        // Add Javascript widget Twig function and loader to frontend
        if ($this->app['config']->getWhichEnd() == 'frontend') {
            $this->addTwigFunction('googlewidget', 'renderWidgetHolder');
            $this->addJavascript('assets/loader.js', array('late' => true, 'priority' => 1000));
        }

        $this->runtime = time();
    }


    /**
     * Set the defaults for configuration parameters
     *
     * @return array
     */
    protected function getDefaultConfig()
    {
        return array(
          # 'app_developer_key' => ''
            'cache' => true,
            'defer' => true,
            'files' => false,
            'filedir' => 'googleplus',
            'profile' => array(
                'user' => 'me',
                'template' => 'gplus_profile.twig'
            ),
            'activity' => array(
                'user' => 'me',
                'results' => 10,
                'template' => 'gplus_feed.twig'
            )
        );
    }


    /**
     * Get the duration (in seconds) for the cache.
     *
     * @return int;
     */
    protected function cacheDuration()
    {
        // in minutes..
        $duration = $this->app['config']->get('general/caching/duration', 10);

        // in seconds.
        return intval($duration) * 60;
    }


    /**
     * Save external files to filesystem
     *
     * @param  $url string
     *
     * @return string
     */
    protected function saveFile ($url)
    {
        if (false === $ext = array_search(
            getimagesize($url)[2], // safe for external URLs
            Extension::$fileFormats,
            true
        )) {
            $this->app['logger.system']->warning("[GooglePlus]: saveFile() remote file '{$url}' has an invalid format.", ['event' => 'extension']);
        } else {
            $basename = sprintf('/%s/%s', $this->config['filedir'], hash('sha1', $url).$ext);
            $filename = sprintf(
                '%s/files%s',
                $this->app['paths']['rootpath'],
                $basename
            );

            // Check for doubles.
            if ( is_file($filename) && filectime($filename) > ($this->runtime - $this->cacheDuration()) ) {
                return $basename;
            }

            // Make sure the folder exists.
            $fileSystem = new Filesystem();
            $fileSystem->mkdir(dirname($filename));

            if (is_writable(dirname($filename))) {
                // Yes, we can create the file!
                $fileSystem->copy($url, $filename, true); // true: make sure, we overwrite an existing old file
                $this->app['logger.system']->info("[GooglePlus]: saveFile() copied file '{$url}' as '{$basename}'.", ['event' => 'extension']);
                return $basename;
            }

            $this->app['logger.system']->warning("[GooglePlus]: saveFile() couldn't write '{$url}' as '{$filename}'.", ['event' => 'extension']);
        }
        return $url;
    }


    /**
     * Replace external file urls with local paths
     *
     * @param  $values array
     *
     * @return array
     */
    protected function localFiles (array $values = array())
    {
        if (!empty($values)) {
            # PROFILE: image
            if (isset($values['record']['image']))
                $values['record']['image']['url'] = $this->saveFile(str_replace('sz=50', 'sz=100', $values['record']['image']['url']));
                // (including a little trick to get a larger profile image)

            # PROFILE: cover
            if (isset($values['record']['cover']))
                $values['record']['cover']['coverPhoto']['url'] = $this->saveFile($values['record']['cover']['coverPhoto']['url']);

            # FEED: attachment image
            if (isset($values['record']['items'])) foreach ($values['record']['items'] as $key => $item) {
                if (count($item['object']['attachments']) > 0) foreach ($item['object']['attachments'] as $a_key => $a_item) {
                    if (isset($a_item['image']))
                        $values['record']['items'][$key]['object']['attachments'][$a_key]['image']['url'] = $this->saveFile($a_item['image']['url']);
                }
            }

        }

        return $values;
    }


    /**
     * Retrieve a fully response object from Google+ API Service.
     *
     * @param  $method  string
     *
     * @return mixed
     */
    protected function handleRequest($method = 'profile')
    {
        $export = array(
            'status' => false,
            'record' => "Edit 'config.yml' to set up your Google API developer key or OAuth2 access."
        );

        // Google+ API Public access
        if (isset($this->config['app_developer_key']) && !empty($this->config['app_developer_key'])) {
            // Factory API base url
            $url = Url::factory(Extension::API);

            // Build API request
            switch ($method) {
                case 'profile':
                default:
                    $url = $url->addPath('/' . $this->config['profile']['user'])
                               ->setQuery('key='.$this->config['app_developer_key']);
                    break;
                case 'activity':
                    $url = $url->addPath('/' . $this->config['activity']['user'] . '/activities/public')
                               ->setQuery('maxResults='.$this->config['activity']['results'].'&key='.$this->config['app_developer_key']);
                    break;
            }

            // Request data
            $request = Client::get($url, Extension::$curl_options)->getBody()->__toString();

            $export = array(
                'status' => true,
                'record' => json_decode($request, true)
            );
        }

        return $export;
    }


    /**
     * Render the Google+ profile box
     *
     * @return string
     */
    public function googlePlusProfile ()
    {
        $cachekey= 'widget_gog_profile';

        if ($this->app['cache']->contains($cachekey)) {
            return $this->app['cache']->fetch($cachekey);
        } else {
            $twigValues = $this->handleRequest('profile');

            if ($this->config['files']) $twigValues = $this->localFiles($twigValues);

            $cache = $this->config['cache'] === true ? $this->cacheDuration() : 0;
            $html = $this->app['render']->render($this->config['profile']['template'], $twigValues)->__toString();
            $this->app['cache']->save($cachekey, $html, $cache);

            return $html;
        }
    }

    /**
     * Render the Google+ public activity feed
     *
     * @return string
     */
    public function googlePlusFeed ()
    {
        $cachekey = 'widget_gog_feed';

        if ($this->app['cache']->contains($cachekey)) {
            return $this->app['cache']->fetch($cachekey);
        } else {
            $twigValues = $this->handleRequest('activity');

            if ($this->config['files']) $twigValues = $this->localFiles($twigValues);

            $cache = $this->config['cache'] === true ? $this->cacheDuration() : 0;
            $html = $this->app['render']->render($this->config['activity']['template'], $twigValues);
            $this->app['cache']->save($cachekey, $html, $cache);

            return $html;
        }
    }


    /**
     * Render the widget holder for Frontend
     * @param  string $type Widgettype
     * @return string       Rendered Twig Markup
     */
    public function renderWidgetHolder($type)
    {
        $str =  sprintf(
            "<section><div class='widget-gog' id='widget-gog-%s' data-key='%s'%s>%s</div></section>",
            $type,
            $type,
            !$this->config['defer'] ? '' : " data-defer='true'",
            $this->config['defer'] ? '' : $this->{'googlePlus'.ucfirst($type)}()
        );

        return new \Twig_Markup($str, 'UTF-8');
    }


    /**
     * Render deferred Request
     * @param  string $type Widgettype
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function renderWidget($type) {
        $this->app['extensions']->clearSnippetQueue();
        $this->app['extensions']->disableJquery();
        $this->app['debugbar'] = false;

        $body = $this->{'googlePlus'.ucfirst($type)}();

        return new Response($body, Response::HTTP_OK, array('Cache-Control' => 's-maxage=180, public'));
    }
}