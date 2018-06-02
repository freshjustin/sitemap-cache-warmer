<?php
/**
 * Class PHP_Warmer
 */
class PHP_Warmer
{
    var $config;
    var $response;
    var $timer;
    var $sleep_time;
    var $context;
    var $from;
    var $to;
    var $workers;
    var $urlProblems = array();
    var $_curl_headers = array();

    function __construct($config)
    {
        $this->config = array_merge(
           array(
               // 'key' => 'default',
               'reportProblematicUrls' => false
           ), $config
        );
        $this->sleep_time = (int)$this->get_parameter('sleep', 0);
        $this->from = (int)$this->get_parameter('from', 0);
        $this->to = (int)$this->get_parameter('to', false);
	    $this->workers = (int)$this->get_parameter('workers', 2);
        $this->response = new PHP_Warmer_Response();
        $this->context = stream_context_create(
            /****
			UNCOMMENT THIS IF YOU USE AN HTTP LOGIN, COMMONLY USED ON TEST ENVS
			array (
				'http' => array (
					'header' => 'Authorization: Basic ' . base64_encode("youruser:yourpassword")
				)
			)
			*/
        );
    }

    function run()
    {
        // Disable time limit
        set_time_limit(0);
        $counter = 0;
        // Authenticate request
        if($this->authenticated_request())
        {
            // URL properly added in GET parameter
            if(($sitemap_url = $this->get_parameter('url')) !== '')
            {
                //Start timer
                $timer = new PHP_Warmer_Timer();
                $timer->start();


                $referer = null ;
                if ( isset($_SERVER['HTTP_HOST']) && isset($_SERVER['REQUEST_URI']) ) {
                    $referer = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ;
                }

                $options = array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_ENCODING => 'gzip',
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_NOBODY => false,
                    CURLOPT_HTTPHEADER => $this->_curl_headers,
                ) ;
                $options[CURLOPT_HTTPHEADER][] = "Cache-Control: max-age=0" ;
                $options[ CURL_HTTP_VERSION_1_1 ] = 1 ;
                $options[CURLOPT_USERAGENT] = 'lscache_runner';
                $options[CURLOPT_HTTPHEADER][] = "Host: canadiancoupons.net";
                if ( !empty($referer) ) {
                    $options[CURLOPT_REFERER] = $referer ;
                }

	            // Discover URL links
	            $urls = $this->process_sitemap($sitemap_url);
	            sort($urls);
	            $urlChunks = array_chunk($urls,$this->workers);

	            // Visit links
	            foreach ($urlChunks as $urls)
	            {
		            $mh = curl_multi_init() ;
		            $ch = array() ;
                    foreach ($urls as $i => $url) {
	                    $ch[ $i ] = curl_init();
	                    curl_setopt( $ch[ $i ], CURLOPT_URL, $url );
	                    curl_setopt_array( $ch[ $i ], $options );
	                    curl_multi_add_handle( $mh, $ch[ $i ] );
                    }
					$running = null;
                    do {
	                    $url_content = curl_multi_exec($mh, $running);
	                    curl_multi_select($mh);
                    } while ($running > 0);

                    foreach ($urls as $i => $url) {
	                    $thisCurl = $ch[$i] ;

	                    curl_multi_remove_handle($mh, $thisCurl) ;
	                    curl_close($thisCurl) ;
                    }
                    curl_multi_close($mh) ;

                    $header['content'] = $url_content;
                    //echo '<pre>', var_dump($header), '</pre>';

                    // Prepare info about URLs with error
                    if ( $url_content === false && $this->config['reportProblematicUrls'] ) {
	                    $this->urlProblems[] = $urls;
                    }

                    if ( ( $this->sleep_time > 0 ) ) {
	                    sleep( $this->sleep_time );
                    }

                    $this->response->set_visited_url( $urls );

                }

                //Stop timer
                $timer->stop();

                // Send timer data to response
                $this->response->set_duration($timer->duration());

                // Done!
                if(sizeof($urls) > 0)
                    $this->response->set_message("Processed sitemap: {$sitemap_url}");
                else
                    $this->response->set_message("Processed sitemap: {$sitemap_url} - but no URL:s were found", 'ERROR');
            }
            else
            {
                $this->response->set_message('Empty url parameter', 'ERROR');
            }
        }
        else
        {
            $this->response->set_message('Incorrect key', 'ERROR');
        }

        if ($this->config['reportProblematicUrls'] && count($this->urlProblems) > 0) {
            @mail($this->config['reportProblematicUrlsTo'], 'Warming cache ends with errors', "Those URLs cannot be warmed:\n" . implode("\n", $this->urlProblems));
        }

        $this->response->display();
    }

    function process_sitemap($url)
    {
        // URL:s array
        $urls = array();

        // Grab sitemap and load into SimpleXML
        $sitemap_xml = @file_get_contents($url,false,$this->context);

        if(($sitemap = @simplexml_load_string($sitemap_xml)) !== false)
        {
            // Process all sub-sitemaps
            if(count($sitemap->sitemap) > 0)
            {
                foreach($sitemap->sitemap as $sub_sitemap)
                {
                    $sub_sitemap_url = (string)$sub_sitemap->loc;
                    $urls = array_merge($urls, $this->process_sitemap($sub_sitemap_url));
                    $this->response->log("Processed sub-sitemap: {$sub_sitemap_url}");
                }
            }

            // Process all URL:s
            if(count($sitemap->url) > 0)
            {
                foreach($sitemap->url as $single_url)
                {
                    $urls[] = (string)$single_url->loc;
                }
            }

            return $urls;
        }
        else
        {
            $this->response->set_message('Error when loading sitemap.', 'ERROR');
            return array();
        }
    }

    /**
     * @return bool
     */
    function authenticated_request()
    {
        return ($this->get_parameter('key') === $this->config['key']) ? true : false;
    }

    /**
     * @param $key
     * @param string $default_value
     * @return mixed
     */
    function get_parameter($key,  $default_value = '')
    {
        return isset($_GET[$key]) ? $_GET[$key] : $default_value;
    }
}