<?php

class Route
{
    public static function execute($delegator, $action, $resource)
    {        
        global $logger;
        
        $action_supported = false;
        
        switch($delegator)
        {
            case "jsconfig": // (partly) external use expected     
                
                // this delegator provides the javascript config file config.js
                $action_supported = self::route_jsconfig($delegator, $action, $resource);
                break;
            
            case "cache": // (partly) external use expected
                
                $action_supported = self::route_cache($delegator, $action, $resource);
                break;
            
            case "cron": // internal use only
                
                $action_supported = self::route_cron($delegator, $action, $resource);
                break;
            
            case "directory": // (partly) external use expected
                
                $action_supported = self::route_directory($delegator, $action, $resource);
                break;
            
            case "proxy": // (partly) external use expected
                
                $action_supported = self::route_proxy($delegator, $action, $resource);
                break;
            
            case "status": // (partly) external use expected
            
                $action_supported = self::route_status($delegator, $action, $resource);
                break;
            
            case "filterkeys": // (partly) external use expected
            
                $action_supported = self::route_filterkeys($delegator, $action, $resource);
                break;
            
            // print the constants defined in this script, not the ones from the config
            // we don't accapt the apache handler to not expose too much information
            case "environment": // internal use only
                
                $action_supported = self::route_environment($delegator, $action, $resource);
                break;        
            
            default:
                $logger->logError("No delegator has been set.");
        }
        
        if(!$action_supported)
            $logger->logError("The action ". $action ." is not supported by the ". $delegator ." delegator.");      
    }
    
    private static function route_jsconfig($delegator, $action, $resource)
    {
        switch($action)
        {
            case "get":
                
                global $logger;
        
                // in some javascript files we need certain settings from the
                // config.php so this script here generates the content for
                // config.js to be included before all other javascript files
                // which require these settings.
                
                header('Content-type: application/x-javascript');
                
                echo "site_url = 'http://". SITE_URL ."';";
                echo "recaptcha_public_key = '" . RECAPTCHA_KEY_PUBLIC . "';";
                
                break;
            
            default:
                return false;
        }
        
        return true;
    }
    
    private static function route_cache($delegator, $action, $resource)
    {
        global $logger;
        
        switch($action)
        {
            case "update":
                
                // we only allow cache updates triggered from cli scripts such as cronjobs
                if(SAPI == "cli")
                    // $resource is the sanitized space name
                    Cache::update($resource);
                else
                    header("Location: http://". SITE_URL . "/error.html");
                
                break;
            
            case "get":
                
                header('Content-type: application/json');
                header('Access-Control-Allow-Origin: *');
                
                $cached = Cache::get($resource);
                
                if(!empty($cached))
                    echo $cached;
                else
                    echo '{ "no": "space"}';
                
                break;
            
            default:
                return false;
        }
        
        return true;
    }
    
    private static function route_cron($delegator, $action, $resource)
    {
        global $logger;
        
        switch($action)
        {
            case "add":
                
                // only allow the creation of a new cron in the cli handler
                if(SAPI == "cli")
                {
                    // if the resource is 'all' this script was most probably
                    // called from the setup script while we assume that no
                    // space will never ever call itself 'all'.
                    if($resource == "all")
                    {
                        $logger->logNotice("Populating all the cron files");
                        
                        $directory = new PrivateDirectory;
                        
                        foreach($directory->get_stdClass() as $space => $url)
                        {
                            Cron::create($space);
                            $space_api_file = Cache::get_from_cache($space);
                            if(!$space_api_file->has_error())
                                Cron::set_schedule($space, $space_api_file->cron_schedule());
                            else
                                $logger->logWarn("Could not schedule the cron.");
                        }
                    }
                    else
                        // in fact this should never be executed because
                        // the single cron creation is done while a new
                        // hackerspace is added within another delegator
                        // (directory:add)
                        Cron::create($resource);
                }
                
                break;
            
            default:
                return false;
        }
        
        return true;
    }
    
    private static function route_directory($delegator, $action, $resource)
    {
        global $logger;
        
        switch($action)
        {
            case "get":
                
                header('Content-type: application/json');
                header('Access-Control-Allow-Origin: *');
                
                $directory = new PublicDirectory;                        
                echo $directory->get();
                
                break;
            
            case "add":
                
                if(SAPI == 'cli')
                {
                    $url = filter_var($resource, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED);

                    if($url == "")
                    {
                        $logger->logDebug("You provided an empty URL");
                        break;
                    }
                                    
                    $space_api_file = new SpaceApiFile($url);
                    $space_name = $space_api_file->name();
                    
                    if($space_api_file->has_error())
                    {
                        echo "Could not add the space \n";
                        $logger->logDebug($space_api_file->error());
                    }
                    else                        
                    {
                        $private_directory = new PrivateDirectory;
                        $public_directory = new PublicDirectory;
                        
                        if(! $private_directory->has_space($space_name))
                        {
                            $private_directory->add_space($space_api_file);
                            $public_directory->add_space($space_api_file);

                            Cron::create($space_api_file->name());
                            Cache::cache($space_api_file);
                            
                            $logger->logDebug("The space got added to the directory.");
                        }
                        else
                            $logger->logDebug("The space is already in the directory.");
                    }
                }
                else
                {
                    // this is executed when somebody adds a space on the website,
                    // when deploying OpenSpaceLint the setup scripts are expected
                    // to have a copy of an existent (and complete) directoy in
                    // the setup directory
                    header('Content-type: application/json');
                    require_once( ROOTDIR . 'c/php/recaptchalib.php');
                    
                    if(isset($_GET["recaptcha_response_field"]))
                    {
                        $resp = recaptcha_check_answer (
                            RECAPTCHA_KEY_PRIVATE,
                            $_SERVER["REMOTE_ADDR"],
                            stripslashes(strip_tags($_GET["recaptcha_challenge_field"])),
                            stripslashes(strip_tags($_GET["recaptcha_response_field"]))
                        );
                        
                        $response = array("ok" => false, "message" => "");
                        
                        if ($resp->is_valid)
                        {
                            // this might be changed to false later
                            $response["ok"] = true;
                            
                            $url = filter_var($_GET['url'], FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED);
                            $space_api_file = new SpaceApiFile($url);
                            $space_name = $space_api_file->name();
                            
                            if($space_api_file->has_error())
                            {
                                $response["ok"] = false;
                                $response["message"] = $space_api_file->error();
                            }
                            else                        
                            {
                                $private_directory = new PrivateDirectory;
                                $public_directory = new PublicDirectory;
                                
                                if(! $private_directory->has_space($space_name))
                                {
                                    $private_directory->add_space($space_api_file);
                                    $public_directory->add_space($space_api_file);
    
                                    Cron::create($space_name);
                                    Cache::cache($space_api_file);
                                    
                                    $response["message"] = "The space got added to the directory.";
                                    
                                    // send an email to the admins
                                    Email::send("New Space Entry: ". $space_name, "",
                                        "The space '" . $space_name . "' has been added to the directory.");
                                }
                                else
                                    $response["message"] = "The space is already in the directory.";                            
                            }
                        }
                        else
                            $response["message"] = $resp->error;
                        
                        $logger->logInfo(
                            "Sending this reponse back to the client:\n",
                            print_r($response, true)
                        );
                        
                        echo json_encode($response);        
                    }
                }
            
                break;
            
            default:
                return false;
        }
        
        return true;
    }
     
    private static function route_filterkeys($delegator, $action, $resource)
    {
        global $logger;
        
        switch($action)
        {
            case "get":
                
                header('Content-type: application/json');
                header('Access-Control-Allow-Origin: *');
                
                echo json_encode(FilterKeys::get());       
                break;
            
            default:
                
                return false;
        }
        
        return true;
    }
   
    private static function route_environment($delegator, $action, $resource)
    {
        global $logger;
        
        switch($action)
        {
            case "get":
                
                if( SAPI == "cli" )                    
                    Utils::print_config(CONFIGDIR . "config.php");
                    
                break;
            
            default:
                
                return false;
        }
            
        return true;
    }
    
    private static function route_proxy($delegator, $action, $resource)
    {
        global $logger;
        
        switch($action)
        {
            case "get":
                
                // the code here is from the former proxy.php (and modified from the original proxy.php from jsonlint.com)
                
                header('Content-type: application/json');
                
                if(SAPI == 'apache')
                {
                    $url = filter_var($_POST['url'], FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED);
                    
                    // data sent with the GET method?
                    if(empty($url))
                       $url = filter_var($_GET['url'], FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED);
                    
                       
                    if (!$url || !preg_match("/^https?:/i", $url))
                    {
                       echo '{ "result": "Invalid URL. Please check your URL and try again.", "error": true }';
                       exit();
                    }
                    
                    $response = DataFetch::get_data($url);
                    
                    // if status >= 400 and contentLength >= 52428800
                    // then null is returned and error messages written
                    // to the output
                    if($response === null)
                       exit();
                       
                    $data = $response->content;
                    
                    if($data === false || is_null($data))
                    {
                       echo '{ "result": "Unable to fetch your JSON file. Please check your server.", "error": true }';
                       exit();
                    }
                    
                    echo json_encode($response);
                }
                
                break;
                
            default:
                    
                return false;
        }
        
        return true;
    }
    
    private static function route_status($delegator, $action, $resource)
    {
        global $logger;
        
        switch($action)
        {
            case "get":
                
                // We go through the list in spaces.json and evaluate the pattern on the given url.
                // A space entry in that file looks like
                //
                // "Shackspace" : {
                //    "url" : "http://shackspace.de/sopen/text/en",
                //    "pattern" : "open",
                //    "inverse" : false
                // }
                //
                // With the inverse field we can specify whether the pattern checks against the
                // open or closed status or not.
                //
                // At the end the status will be appended to the corresponding space json file.
                
                header('Content-type: application/json');
                header('Access-Control-Allow-Origin: *');
                
                $spaces = file_get_contents(ROOTDIR . "c/spacehandlers/spaces.json");
                $spaces = json_decode($spaces);
                
                foreach($spaces as $space => $val)
                {
                    if($space == $resource)
                    {
                        $file = ROOTDIR . "c/spacehandlers/". NiceFileName::json($space);
                        $url = $val->url;
                        $pattern = $val->pattern;
                        $inverse = (bool) $val->inverse;
                        
                        // we no longer need to iterate over the other space handlers 
                        break;
                    }
                }
                
                if(isset($file) && file_exists($file))
                {
                    // we do no checks on the json, we assume it's validated with openspacelint
                    $spacejson = json_decode(file_get_contents($file));
                    
                    $data = DataFetch::get_data($url);
                    
                    // the status in this place might still be open or close
                    $status = (bool) preg_match("/$pattern/", $data->content);
                    
                    // with the inverse flag we know if we were checking the open or closed status
                    if($inverse)
                        $status = ! $status;
                        
                    $spacejson->open = $status;
                    echo json_encode($spacejson);
                }
                
                break;
            
            default:
                
                return false;
        }
        
        return true;
    }
}
