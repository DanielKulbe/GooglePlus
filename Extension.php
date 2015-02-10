<?php
// Google+ extension for Bolt

namespace Bolt\Extension\Umbrielsama\GooglePlus;

use Bolt\Application;
use Bolt\BaseExtension;
use Bolt\Helpers\String;
use Guzzle\Http\StaticClient as Client;
use Guzzle\Http\Url as Url;
use Symfony\Component\Filesystem\Filesystem;

class Extension extends BaseExtension
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

        // Add widgets
        $cache = $this->config['cache'] === true ? $this->cacheDuration() : 0;
        $this->addWidget('googleplus', 'profile', 'googlePlusProfile', '', true, $cache);
        $this->addWidget('googleplus', 'feed', 'googlePlusFeed', '', true, $cache);

        $this->runtime = time();
    }


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
     * Set the defaults for configuration parameters
     *
     * @return array
     */
    protected function getDefaultConfig()
    {
        return array(
          # 'app_developer_key' => ''
            'cache' => true,
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
            $this->app['log']->add("saveFile: remote file '$url' has an invalid format.", 2);
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
                $this->app['log']->add("saveFile: copied file '$url' as '$basename'.", 2);
                return $basename;
            }

            $this->app['log']->add("saveFile: couldn't write '$url' as '$filename'.", 2);
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
            'record' => "Edit 'config.yml' to set up your Google API developer key."
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
        $twigValues = $this->handleRequest('profile');

        if ($this->config['files']) $twigValues = $this->localFiles($twigValues);

        $str = $this->app['render']->render($this->config['profile']['template'], $twigValues);

        return new \Twig_Markup($str, 'UTF-8');
    }

    /**
     * Render the Google+ public activity feed
     *
     * @return string
     */
    public function googlePlusFeed ()
    {
        $twigValues = $this->handleRequest('activity');

        if ($this->config['files']) $twigValues = $this->localFiles($twigValues);

        $str = $this->app['render']->render($this->config['activity']['template'], $twigValues);

        return new \Twig_Markup($str, 'UTF-8');
    }
}
