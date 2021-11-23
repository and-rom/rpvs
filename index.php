<?php
function version() {
    $major = "1";
    $minor = trim(exec('git rev-list HEAD | wc -l'));
    $hash = trim(exec('git log --pretty="%h" -n1 HEAD'));
    $date = new \DateTime(trim(exec('git log -n1 --pretty=%ci HEAD')));
    $date->setTimezone(new \DateTimeZone('UTC'));

    return sprintf('v%s.%s-%s (%s)', $major, $minor, $hash, $date->format('Y-m-d H:i:s'));
}

if (isset($_GET) && count($_GET)) {

    $action = (isset($_GET['action']) ? $_GET['action'] : "");
    $response = new stdClass;

    session_start();

    require_once('vendor/autoload.php');
    require_once("config.php");

    $provider = new Rudolf\OAuth2\Client\Provider\Reddit([
            'clientId'      => $client_id,
            'clientSecret'  => $client_secret,
            'userAgent'     => 'any:rpvs:1, (by /u/1n5820)',
            'redirectUri'   => $_SERVER['REQUEST_SCHEME']."://".$_SERVER['SERVER_NAME'].(($_SERVER['SERVER_PORT'] != '80' && $_SERVER['SERVER_PORT'] != '443') ? ':' . $_SERVER['SERVER_PORT'] : '').dirname($_SERVER['SCRIPT_NAME']),
    ]);



    if(isset($_SESSION['oauth2token'])) {
	    $oauth2token = unserialize($_SESSION['oauth2token']);
	    if($oauth2token->hasExpired()) {
	        try {
		        $oauth2token = $provider->getAccessToken('refresh_token', [
					    'refresh_token' => $oauth2token->getRefreshToken()
        			]);
		        $_SESSION['oauth2token'] = serialize($oauth2token);
		        header('Location: ./');
		        exit;
	        } catch (League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
	            $response->code = 204;
    	        $response->msg = $e->getMessage();
	        }
	    }

    $options['raw_json'] = 1;
    $options['limit'] = 20;
    switch ($action) {
        case "feed":
            $sort = isset($_GET['sort']) ? $_GET['sort'] : "";
            $options['after'] = isset($_GET['after']) ? $_GET['after'] : "";
            $options['headers']['Accept'] = 'application/json';
            $apiRequest = $provider->getAuthenticatedRequest(
                'GET',
                'https://oauth.reddit.com/' . $sort . '.json?' . http_build_query($options),
                $oauth2token
            );
            $apiResponse = $provider->getResponse($apiRequest);
            $content = (string) $apiResponse->getBody();
            $apiResponse = json_decode($content);
            $posts = $apiResponse->data->children;
            $response->posts = [];
            foreach ($posts as $post) {
                $last_id = $post->data->id;
                $obj = new stdClass;
                if (isset($post->data->post_hint)) {
                    switch ($post->data->post_hint) {
                        case "image":
                            $obj->type = "photo";
                            break;
                        case "gallery":
                            $obj->type = "gallery";
                            break;
                        case "link":
                            if (isset($post->data->preview->reddit_video_preview->fallback_url)) {
                                $obj->type = "video";
                            } else {
                                $obj->type = "link";
                                //continue 2;
                            }
                            break;
                        case "rich:video":
                            $obj->type = "video";
                            break;
                        default:
                            $obj->type = "unknown";
                            break;
                    }
                } elseif (isset($post->data->is_gallery) && $post->data->is_gallery) {
                    $obj->type = "gallery";
                } elseif (isset($post->data->domain) && $post->data->domain == "i.redd.it") {
                    $obj->type = "photo";
                } else {
                    $obj->type = "unknown";
                   //continue;
                }
                $obj->id = $post->data->id;
                $obj->title = (isset($post->data->title) ? $post->data->title : "" );
                $obj->subreddit = $post->data->subreddit;
                $obj->author = $post->data->author;
                $obj->locked = $post->data->locked;
                if (isset($post->data->link_flair_text)) {
                    $obj->link_flair_text = $post->data->link_flair_text;
                    $obj->link_flair_text_color = $post->data->link_flair_text_color;
                    $obj->link_flair_background_color = $post->data->link_flair_background_color;
                }
                $obj->is_original_content = $post->data->is_original_content;
                $obj->domain = $post->data->domain;
                $obj->created = $post->data->created;

                $obj->score = $post->data->score;
                $obj->num_comments = $post->data->num_comments;
                $obj->over_18 = $post->data->over_18;
                $obj->all_awardings = [];
                foreach ($post->data->all_awardings as $award) {
                    $obj->all_awardings[] = $award->resized_static_icons[0]->url;
                }
                $obj->total_awards_received = $post->data->total_awards_received;

                $obj->url = $post->data->url;
                //echo $post->data->id."</br>\n";
                switch ($obj->type) {
                    case "photo":
                        $obj->src = $post->data->url;
                        $response->posts[] = clone $obj;
                        break;
                    case "video":
                        if (isset($post->data->preview->reddit_video_preview->fallback_url)) {
                            $obj->src = $post->data->preview->reddit_video_preview->fallback_url;
                            $obj->preview = $post->data->preview->images[0]->source->url;
                            $response->posts[] = clone $obj;
                        }
                        break;
                    case "gallery":
                        foreach ($post->data->gallery_data->items as $item) {
                            if ($post->data->media_metadata->{$item->media_id}->status == 'valid') {
                                if (isset($post->data->media_metadata->{$item->media_id}->s->mp4)) {
                                    $obj->src = $post->data->media_metadata->{$item->media_id}->s->mp4;
                                    $obj->type = "video";
                                } elseif (isset($post->data->media_metadata->{$item->media_id}->s->gif)) {
                                    $obj->src = $post->data->media_metadata->{$item->media_id}->s->gif;
                                    $obj->type = "photo";
                                } elseif (isset($post->data->media_metadata->{$item->media_id}->s->u)) {
                                    $obj->src = $post->data->media_metadata->{$item->media_id}->s->u;
                                    $obj->type = "photo";
                                } else {
                                    continue;
                                }
                                $response->posts[] = clone $obj;
                            }
                        }
                        break;
                }
            }

            $response->after = (isset($apiResponse->data->after)) ? $apiResponse->data->after : $last_id;
            $response->code = 200;
            break;
        default:
            $response->msg = "Method Not Allowed";
            $response->code = 405;
            break;
    }

    } else if(isset($_GET['code']) && isset($_SESSION['oauth2state']) && isset($_GET['state'])) {
	    if($_GET['state'] == $_SESSION['oauth2state']) {
		    unset($_SESSION['oauth2state']);
		    try {
		        $oauth2token = $provider->getAccessToken('authorization_code', [
                    'code' => $_GET['code'],
                    'state' => $_GET['state']
                ]);
		        $_SESSION['oauth2token'] = serialize($oauth2token);
		        header('Location: ./');
		        exit;
		    } catch (League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
	            $response->code = 204;
    	        $response->msg = $e->getMessage();
	        }
	    }
	    else {
		    exit("Returned state didn't match the expected value. Please go back and try again.");
	    }
    } else {
	    $authorizationUrl = $provider->getAuthorizationUrl([
            'scope' => ['account', 'creddits', 'edit', 'flair', 'history', 'identity', 'modconfig', 'modcontributors', 'modflair', 'modlog', 'modmail', 'modothers', 'modposts', 'modself', 'modwiki', 'mysubreddits', 'privatemessages', 'read', 'report', 'save', 'structuredstyles', 'submit', 'subscribe', 'vote', 'wikiedit', 'wikiread'],
            'duration' => "permanent"
        ]);
	    $_SESSION['oauth2state'] = $provider->getState();
            $response->code = 511;
	        $response->msg = "Authorization Required";
	        $response->auth_url = $authorizationUrl;
    }
    //$response->debug_content = isset($content) ? $content : "";
    //$response->debug_request = isset($content) ? 'https://oauth.reddit.com/' . $sort . '.json?' . http_build_query($options) : "";
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response ,JSON_UNESCAPED_UNICODE);

    exit;
}
?>
<!DOCTYPE html>
<!-- <?php echo version();?> -->
<html>
<head>
  <title>Reddit Photo Video Slider</title>
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta charset="utf-8">
  <meta name="description" content="">
  <meta name="author" content="">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
  <meta http-equiv="Cache-Control" content="max-age=3600, must-revalidate">
  <meta name="theme-color" content="#222222" />
  <link rel="icon" href="/img/rpvs16.png" type="image/png">
  <script type="text/javascript" src="js/js.cookie.min.js"></script>
  <script type="text/javascript" src="js/jquery-3.6.0.min.js"></script>
  <script type="text/javascript">
  $(document).ready(function(){
    var currentLayout;

    var layouts = [];

    var layout$ = {
        // Properties
        layoutType: "feed",
        sort: "new",
        type:"all",
        currentSlide:0,
        after:"",
        slides: [],
        noMore: false,
        iframe: null,
        locked: false,
        wasHidden: false,
        updateLocked: false,
        // Methods
        save: function(){
            Cookies.set("layoutType", this.layoutType, { expires : 0.5 });
            Cookies.set("sort", this.type, { expires : 0.5 });
            Cookies.set("type", this.type, { expires : 0.5 });
        },
        update: function(restore = false, layoutType = "", sort = "", after = "", type = ""){
            if (this.updateLocked) {
                return;
            }
            $("#loader").show();
            //this.after = after != "" ? after : this.slides.length != 0 ? this.layoutType == "likes" ? this.slides[this.slides.length-1].liked_timestamp : this.last_id : "";

            if (restore && layoutType == "feed" && this.type != type) { this.type = type }

            $.ajax({
                dataType: "json",
                url: "./index.php",
                async: true,
                data: {action: layoutType != "" ? layoutType : this.layoutType,
                       after: this.after,
                       sort:  this.sort,
                       type:  type != "" ? type : this.type,
                       },
                context: this,
                success: restore ? this.restore : this.response,
                error: this.error
            });
        },
        restore:  function(data){
            this.slides = [];
            this.response(data);
            setMessage("Restored");
        },
        response: function(data){
            switch (data.code) {
                case 200:
                    $(document).trigger('auth');
                    if (data.hasOwnProperty('posts')) {
                        $("#loader").hide();
                        if (data.posts.length == 0) {
                            this.noMore = true;
                            setMessage("No more posts.");
                        }
                        this.slides = this.slides.concat(data.posts);
                        this.updateLocked = false;
                        if (this.currentSlide == 0) {
                            this.display();
                        }
                    }
                    if (data.hasOwnProperty('after')) this.after = data.after;
                    //if (data.hasOwnProperty('debug_content')) this.debug_content = data.debug_content;
                    //if (data.hasOwnProperty('debug_request')) this.debug_request = data.debug_request;
                    break;
                case 404:
                    /*setMessage(data.msg);
                    $("#loader").hide();
                    if (data.hasOwnProperty('posts')) {
                        if (data.posts.length == 0) this.noMore = true;
                        this.slides = this.slides.concat(data.posts);
                    }
                    this.updateLocked = false;
                    this.clearPostInfo();
                    $("#header, #footer").show();*/
                    break;
                case 511:
                    $("#loader").hide();
                    setMessage(data.msg);
                    if (data.hasOwnProperty('auth_url')) {
                        setTimeout(function(){
                            window.location.href = data.auth_url;
                        },1500)
                    }
                    break;
                case 201:
                case 202:
                default:
                    $("#loader").hide();
                    setMessage(data.msg);
                    break;
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            setMessage("Update error!");
            this.updateLocked = false;
        },
        lock: function() {
            $("#loader").show();
            this.locked = true;
        },
        unlock: function() {
            if (!this.updateLocked) $("#loader").hide();
            this.locked = false;
        },
        checkHidden: function() {
            if ($('body').is(":visible")) {
                this.wasHidden = false;
            } else {
                this.wasHidden = true;
            }
        },
        display: function(){
            this.lock();
            if (this.slides.length == 0) {
                this.unlock();
                this.clearPostInfo(true);
                $("#header, #footer").show();
                return;
            } else {
                this.clearPostInfo();
            }
            this.displayPostInfo();
            if (this.slides[this.currentSlide].type == "photo") {
                this.displayPhoto();
            } else {
                this.displayVideo();
            }

            if (this.currentSlide-1 < 0 ) {
                Cookies.set("before", "", { expires : 0.5 });
            } else {
                Cookies.set("before", this.slides[this.currentSlide-1].id, { expires : 0.5 });
            }
        },
        displayPostInfo: function() {
            $("#title").html(this.slides[this.currentSlide].title).show();
            $("#subreddit").html(this.slides[this.currentSlide].subreddit).show();
            $("#author").html(this.slides[this.currentSlide].author).show();
            $("#locked").toggle(this.slides[this.currentSlide].locked);
            if (this.slides[this.currentSlide].link_flair_text) {
                $("#flair").empty().append($('<span />', {
                    style: "background-color: " + (this.slides[this.currentSlide].link_flair_background_color != "" ? this.slides[this.currentSlide].link_flair_background_color : "grey") +
                            ";color: " + (this.slides[this.currentSlide].link_flair_text_color == "light" ? "white" : "black"),
                }).html(this.slides[this.currentSlide].link_flair_text)).show();
            }
            $("#domain").html(this.slides[this.currentSlide].domain).show();
            $("#date").append($("<span />", {
                title: this.dateTime(this.slides[this.currentSlide].created)
                }).html(this.age(this.slides[this.currentSlide].created))).show();
            $("#score").html(this.slides[this.currentSlide].scor > 1000 ? Math.round(this.slides[this.currentSlide].scor/100)/10 + "K" : this.slides[this.currentSlide].score).show();
            $("#num_comments span").html(this.slides[this.currentSlide].num_comments); $("#num_comments").show();
            $("#nsfw").toggle(this.slides[this.currentSlide].over_18);
            if (this.slides[this.currentSlide].all_awardings.length > 0) {
                this.slides[this.currentSlide].all_awardings.forEach((award) => {
                    $('<img />', {src: award}).appendTo("#all_awardings")

                });
                $("#all_awardings").show();
            }
            if (this.slides[this.currentSlide].total_awards_received > 0) {
                $("#total_awards_received span").html(this.slides[this.currentSlide].total_awards_received); $("#total_awards_received").show();
            }
        },
        clearPostInfo: function(partial = false) {
            $("#title").empty().hide();
            $("#subreddit").empty().hide();
            $("#author").empty().hide();
            $("#locked").hide();
            $("#flair").empty().hide();
            $("#domain").empty().hide();
            $("#date").empty().hide();
            $("#score").empty().hide();
            $("#num_comments span").empty(); $("#num_comments").hide();
            $("#nsfw").hide();
            $("#all_awardings").empty().hide();
            $("#total_awards_received span").empty(); $("#total_awards_received").hide();
        },
        displayPhoto: function() {
            this.iframe = new Image();
            this.iframe.src = this.slides[this.currentSlide].src;
            var _this = this;
            this.iframe.onerror = function() {
                $('#content').empty();
                setMessage("Load error");
                _this.unlock();
            };
            this.iframe.onload = function() {
                $('#content').empty();
                $(this).appendTo('#content').attr('id',"photo").addClass("photo");
                _this.resize();
                _this.unlock();
            };
        },
        displayVideo: function() {
            $('#content').empty().append($('<video />', {
                id: 'video',
                src: this.slides[this.currentSlide].src,
                type: 'video/mp4',
                loop: ''
            }));
            this.iframe = $('video').first();
            this.resize();

            $(this.iframe).removeAttr("controls");
            $(this.iframe).prop("controls",false);

            $(this.iframe).prop("autoplay",true);

            $(this.iframe).removeAttr("muted");
            $(this.iframe).prop("muted",videoMuted);
            $(this.iframe).prop("preload","none");

            var _this = this;
            var img = new Image();
            img.src = this.slides[this.currentSlide].preview;
            img.onload = function(){
                _this.unlock();
            };



            $(this.iframe).find('source').last().on('error', function(e) {
                $("#content").empty().append($("#error-icon").clone());
                _this.unlock();
            });
            $(this.iframe).on("play",function (e){
                _this.unlock();
            })
            .on('loadstart', function (event) {
                $("#loader").show();
            })
            .on('loadeddata', function (event) {
                _this.iframe[0].play();
            })
            .on('seeking', function (event) {
                $("#loader").show();
            })
            .on('waiting', function (event) {
                $("#loader").show();
            })
            .on('canplay', function (event) {
                $("#loader").hide();
            });
        },
        show: function(whereTo) {
            if (!this.locked) {
                if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined' && this.slides[this.currentSlide].video_type == "tumblr") {
                    this.iframe[0].pause();
                    $(this.iframe).attr('src','');
                    $(this.iframe).find('source').last().attr('src','');
                    this.iframe[0].load();
                    }
                var status;
                if (whereTo > 0) {
                    status = this.prev();
                } else {
                    status = this.next();
                }
                if (status) {
                    this.display();
                }
            }
        },
        next: function(){
            if (this.currentSlide+1 > this.slides.length-5) {
                if (!this.noMore) this.update();
                this.updateLocked = true;
            }
            if (this.currentSlide < this.slides.length-1) {
                this.currentSlide++;
                return true;
            } else {
                return false;
            }
        },
        prev: function(){
            if (this.currentSlide > 0) {
                this.currentSlide--;
                return true;
            } else {
                return false;
            }
        },
        resize: function(){
            this.checkHidden();
            if (this.wasHidden) {
                return;
            }
            var elmt = window, prop = "inner";
            if (!("innerWidth" in window)) {
                elmt = document.documentElement || document.body;
                prop = "client";
            }
            var /*ww = elmt[prop + "Width"],
                wh = elmt[prop + "Height"],*/
                ww = Math.min(document.documentElement.clientWidth,window.innerWidth||0),
                wh = Math.min(document.documentElement.clientHeight,window.innerHeight||0),
                iw = $(this.iframe).width(),
                ih = $(this.iframe).height(),
                rw = wh / ww,
                ri = ih / iw,
                newWidth,
                newHeight;
            if (rw < ri) {
               newWidth = wh / ri;
               newHeight = wh;
            } else {
                newWidth = ww;
                newHeight = ww * ri;
            }
            properties = {
                width: newWidth + "px",
                height: newHeight + "px",
                top: (wh - newHeight) / 2,
                left: (ww - newWidth) / 2
            };
            $(this.iframe).css(properties);
        },
        age: function(timestamp) {
            var elapsed = new Date() - new Date(timestamp*1000);

            if (elapsed < 60000) {return 'Now';}
            else if (elapsed < 3600000) {return Math.round(elapsed/60000) + ' m';}
            else if (elapsed < 86400000 ) {return Math.round(elapsed/3600000) + ' h';}
            else if (elapsed < 2592000000) {return Math.round(elapsed/86400000) + ' d';}
            else if (elapsed < 31536000000) {return Math.round(elapsed/2592000000) + ' mo';}
            else {return Math.round(elapsed/31536000000) + ' y';}
        },
        dateTime: function(timestamp) {
            var pubDate = new Date(timestamp*1000);

            return pubDate.toLocaleDateString() + " " + pubDate.toLocaleTimeString();
        },
        seek: function(direction) {
            if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined' && !isNaN(this.iframe[0].duration)) {
                stepPosition = Math.round(this.iframe[0].duration * 0.05);
                if (stepPosition < 1) stepPosition = 1;
                var newPosition = this.iframe[0].currentTime + stepPosition*direction;
                if (newPosition < 0)
                    newPosition = 0;
                else
                    if (newPosition > this.iframe[0].duration)
                        newPosition = this.iframe[0].duration - 1;
                this.iframe[0].currentTime = newPosition;
                setMessage(formatTime(newPosition) + " / " + formatTime(this.iframe[0].duration) + " (" + ( direction>0 ? "+" : "-" ) + stepPosition + ")", "timer");
            }
        },
        muteToggle: function() {
            if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined' && !isNaN(this.iframe[0].duration)) {
                videoMuted = !videoMuted;
                this.iframe[0].muted = videoMuted;

                $("#content svg.volume-icon").remove();
                $("#content").append($("#" + (this.iframe[0].muted ? "mute-icon" : "unmute-icon")).clone());

                setTimeout(function() {
                    $("#content svg.volume-icon").remove();
                }, 800);
            }
        },
        test: function(){
            console.log("this.updateLocked: " + this.updateLocked);
            console.log("this.locked: " + this.locked);
            console.log("this.layoutType: " + this.layoutType);
            console.log("this.blog: " + this.blog);
            console.log("this.type: " + this.type);
            console.log("this.tag: " + this.tag);
            console.log("this.own: " + this.own);
            console.log("this.currentSlide: " + this.currentSlide);
            console.log("this.currentPage: " + this.currentPage);
            console.log("this.slides.length: " + this.slides.length);
            console.log("this.slides");
            console.log(this.slides);
            console.log(this.debug_request);
            console.log(this.debug_content);
        }
    };

    window.__igEmbedLoaded = function( loadedItem ) {
        console.log("??");
    };

    var messageTimer;
    function setMessage (text, id="") {
         if (id == ""){
            $("#messages").append($("<span> </span>").html(text));
            setTimeout(function() {
                $("#messages span").first().remove();
            }, 5000);
        } else {
            if(!$('#messages span#' + id).length)
                $("#messages").append($("<span id=" + id + "> </span>").html(text));
            else
                $("#messages span#" + id).html(text);

            if (messageTimer) {
                clearTimeout(messageTimer);
                messageTimer = 0;
            }
            messageTimer = setTimeout(function() {
                $("#messages span#" + id).first().remove();
                }, 1000);
        }
    }

    function formatTime (seconds) {
        var hh = Math.floor(seconds / 3600);
        var mm = Math.floor(seconds%3600 / 60);
        var ss = Math.round(seconds%3600 % 60);
        return (hh>0 ? (hh<10 ? "0" : "") + hh + ":" : "") + (mm<10 ? "0" : "") + mm + ":" + (ss<10 ? "0" : "") + ss;
    }

    layouts.push({
        __proto__: layout$
    });

    currentLayout = layouts[0];

    currentLayout.update();

    $(document).one('auth',function (e){
        if (typeof Cookies.get("before") !== 'undefined') {
            if (confirm('Do you want to restore dash?')) {
                $('#content').empty();
                if (Cookies.get("layoutType") == "dash") {
                    currentLayout.update(true, Cookies.get("layoutType"),Cookies.get("blog"),Cookies.get("before"),Cookies.get("type"));
                } else {
                    layouts.push({
                        __proto__: layout$,
                        layoutType: Cookies.get("layoutType"),
                        blog: Cookies.get("blog"),
                        type: Cookies.get("type")
                    });
                    currentLayout = layouts[layouts.length-1];
                    currentLayout.update(true, "","",Cookies.get("before"),"");
                }
                $("#type").val(currentLayout.type);
                $("#back").toggle(currentLayout.layoutType != "dash");
                $("#header, #footer").hide();
            }
        }
        currentLayout.save();
    });

    $(window).resize(function (e){
        currentLayout.resize()
    });

    $("#content").on('click',function (e){
        if ($(currentLayout.iframe).is("video")) {
            if ($(currentLayout.iframe)[0].paused) {
                $(currentLayout.iframe)[0].play();
                $("#header, #footer").hide();
            } else {
                $(currentLayout.iframe)[0].pause();
                $("#header, #footer").show();
            }
        } else {
            $("#header, #footer").toggle();
        }
    });
    $("#type").change(function (e){
        $("#header, #footer").hide();
        $('#content').empty();
        currentLayout.type=this.value;
        currentLayout.currentSlide=0;
        currentLayout.currentPage=0;
        currentLayout.slides=[];
        currentLayout.update();
        currentLayout.save();
        $("#header, #footer").hide();
    });
    var timer;
    var hided = false;
    $(window).on("mousemove",function () {
        if (!hided) {
            if (timer) {
                clearTimeout(timer);
                timer = 0;
            }
        } else {
            $('html').css({cursor: ''});
            hided = false;
        }
        timer = setTimeout(function () {
            $('html').css({cursor: 'none'});
            hided = true;
        }, 2000);
    });
    var stealthMode = !( navigator.userAgent.match(/Android/i)    ||
                         navigator.userAgent.match(/webOS/i)      ||
                         navigator.userAgent.match(/iPhone/i)     ||
                         navigator.userAgent.match(/iPad/i)       ||
                         navigator.userAgent.match(/iPod/i)       ||
                         navigator.userAgent.match(/BlackBerry/i) ||
                         navigator.userAgent.match(/Windows Phone/i));
    var videoMuted = true;
    $(window).on('mouseleave blur focusout', function (e) {
        e.stopPropagation();
        e.preventDefault();
        if (stealthMode) {
            $('body').hide();
        }
    });
    var keyHidden = false;
    $(window).on('mouseenter mouseover', function (e) {
        e.stopPropagation();
        e.preventDefault();
        if (stealthMode && !keyHidden) {
            $('body').show();
            if (currentLayout.wasHidden) {
                currentLayout.resize();
            }
        }
    });
    // keybindings
    var enabled = true;
    $(document).on('keydown',function(e){
        var code = (e.keyCode ? e.keyCode : e.which);
        if (e.altKey && code == 81) { // Alt + 'q'
            enabled = !enabled;
            setMessage("Hot keys " + (enabled ? "enabled" : "disabled"));
            return;
        }
        if (enabled) switch (code){
            case 37:  // left
            case 65:  // 'a'
                currentLayout.show(1);
                break;
            case 39: // right
            case 68: // 'd'
                currentLayout.show(-1);
                break;
            case 81: // 'q'
                // home
                $("#home").click();
                break;
            case 87: // 'w'
                // back
                $("#back").click();
                break;
            case 69: // 'e'
                // blog
                $("#blog-name").click();
                break;
            case 83: // 's'
                // reblogged from
                $("#reblogged-from").click();
                break;
            case 88: // 'x'
                // source
                $("#source").click();
                break;
            case 70: // 'f'
                // follow
                $("#follow").click();
                break;
            case 78: // 'n'
                // photo
                $("#type").val("photo").trigger('change');
                break;
            case 66: // 'b'
                // both
                $("#type").val("all").trigger('change');
            case 86: // 'v'
                // video
                $("#type").val("video").trigger('change');
                break;
            case 84: // 't'
                // open post
                $("#open-post").click();
                break;
            case 76: // 'l'
                // like post
                $("#like-post").click();
                break;
            case 32: // space
                $("#content").click();
                break;
            case 67: // 'c'
                currentLayout.seek(1);
                break;
            case 90: // 'z'
                currentLayout.seek(-1);
                break;
            case 220: // '\'
                stealthMode = !stealthMode;
                videoMuted = stealthMode;
                setMessage("Stealth mode " + (stealthMode ? "enabled" : "disabled"));
                break;
            case 192: // '`'
                if (stealthMode) {
                    $('body').toggle();
                    keyHidden = $('body').is( ":hidden" );
                    if (currentLayout.wasHidden) {
                        currentLayout.resize();
                    }
                }
                break;
            case 77: // 'm'
                currentLayout.muteToggle();
                break;
            case 73: // 'i'
                currentLayout.test();
                break;
            default:
                break;
        }
    });
    // mouse wheel
    $("#content").on('mousewheel DOMMouseScroll',function (e){
        e.stopPropagation();
        currentLayout.show(parseInt(e.originalEvent.wheelDelta || - e.originalEvent.detail));
    });
    // touch
    var xDown,yDown,xUp,yUp = null;
    var xDiffPrev = 0;
    var touchOff = false;
    var mouseButtonDown = false;
    $("#content").bind('touchstart', function (ev) {
        ev.stopPropagation();
        if ( touchOff ) {return;}
        var e = ev.originalEvent;
        xDown = e.touches[0].clientX;
        yDown = e.touches[0].clientY;
    });
    $("#content").mousedown(function(ev) {
        var e = ev.originalEvent;
        if (e.which ==  2) {
            mouseButtonDown = true;
            xDown = e.clientX;
            yDown = e.clientY;
        }
    });
    $("#content").bind('touchmove', function (ev) {
        ev.stopPropagation();
        if ( touchOff ) {return;}
        var e = ev.originalEvent;
        if ( ! xDown || ! yDown ) {return;}
        xUp = e.touches[0].clientX;
        yUp = e.touches[0].clientY;
        var xDiff = xDown - xUp;
        var yDiff = yDown - yUp;
        var direction = 0;
        if ( Math.abs( xDiff ) > Math.abs( yDiff ) ) {
            if ( xDiff > 0 ) {
                direction = 1;
            } else if (xDiff < 0) {
                direction = -1;
            }
            if ( Math.abs(xDiff - xDiffPrev) > 30 ) {
                currentLayout.seek(-direction);
                xDiffPrev = xDiff;
            }
        }/* else {
            if ( yDiff > 25 ) {
                currentLayout.show(-1);
            } else if (yDiff < -25) {
                currentLayout.show(1);
            }
        }*/
    });
    $("#content").mousemove(function(ev) {
        if (!mouseButtonDown) {return;}
        if ( ! xDown || ! yDown ) {return;}
        var e = ev.originalEvent;
        xUp = e.clientX;
        yUp = e.clientY;
        var xDiff = xDown - xUp;
        var yDiff = yDown - yUp;
        var direction = 0;
        if ( Math.abs( xDiff ) > Math.abs( yDiff ) ) {
            if ( xDiff > 0 ) {
                direction = 1;
            } else if (xDiff < 0) {
                direction = -1;
            }
            if ( Math.abs(xDiff - xDiffPrev) > 30 ) {
                currentLayout.seek(-direction);
                xDiffPrev = xDiff;
            }
        }
    });
    $("#content").bind('touchend', function (ev) {
        ev.stopPropagation();
        if ( touchOff ) {return;}
        if ( typeof xUp == 'undefined' || ! xUp || ! yUp ) {return;}
        var xDiff = xDown - xUp;
        var yDiff = yDown - yUp;
        if ( Math.abs( xDiff ) > Math.abs( yDiff ) ) {
            /*if ( xDiff > 25 ) {
                //currentLayout.show(-1);
            } else if (xDiff < -25) {
                //currentLayout.show(1);
            }*/
        } else {
            if ( yDiff > 25 ) {
                currentLayout.show(-1);
            } else if (yDiff < -25) {
                currentLayout.show(1);
            }
        }
        xDown = null;
        yDown = null;
        xUp = null;
        yUp = null;
    });
    $("#content").mouseup(function(ev) {
        var e = ev.originalEvent;
        if (e.which ==  2) {
            mouseButtonDown = false;
            if ( typeof xUp == 'undefined' || ! xUp || ! yUp ) {return;}
            xDown = null;
            yDown = null;
            xUp = null;
            yUp = null;
        }
    });
  });
  </script>
  <style type="text/css">
    * {
        margin: 0;
        padding: 0;
        border: 0;
    }
    html,body {
        height: 100%;
        background:black;
        touch-action: none;
        font-family: sans-serif;
    }
    a {
        color:white;
    }
    #header, #messages {
        overflow-y: scroll;
        color:white;
        background: rgba(40, 40, 40, .5);
        text-shadow: 1px 1px 3px black, -1px -1px 3px black, -1px 1px 3px black, 1px -1px 3px black;
        z-index:1;
    }
    #header::-webkit-scrollbar, #messages::-webkit-scrollbar {
        display: none;
    }
    #messages {
        max-height:10%;
        position: relative;
        text-align: center;
    }
    #messages span{
        display:block;
    }
    #header {
        display:none;
        min-height:50px;
        max-height:50%;
        /*width: 100%;*/
        width: calc(100% - 30px);
        padding: 10px;
        position: relative;
        top: 0;
        left:0;
        /*text-align:center;*/
        font-size:0;
        color: darkgray;
    }
    /*#header:after {
        content: '';
        display: block;
        clear: both;
    }*/
    .header-container {
        display:inline-block;
        font-size: initial;
    }
    .header-container.with-dot::after {
        content: '\2219';
        color: darkgray;
        margin: 0 5px;
        font-weight: normal;
        font-size: initial;
    }
    .row {
        margin: 5px 0;
    }
    #title {
        display: block;
        font-size:large;
        color: white;
    }
    #subreddit {
        color: dodgerblue;
    }
    #flair span {
        padding: 2px;
        text-shadow: none;
    }
    #score {
        font-weight: bold;
        font-size: large;
    }
    #nsfw {
        text-shadow: none;
        vertical-align: bottom;
        margin: 0 8px;
    }
    #all_awardings img {
        vertical-align: middle;
    }
    #total_awards_received {
        margin-left: 2px;
        font-size: small;
    }
    #buttons{
        float: right;
        margin-right: 10px;
        text-align: right;
    }
    .button {
        text-decoration: none;
        margin-right: 1em;
    }
    .button:last-child {
        margin-right: initial;
    }
    select {
        border: 0.02px solid white;
        color: white;
        background-color: transparent;
        text-indent: 0.01px;
        height: 1.5em;
        padding-left: 1ex;
        margin-right: 1.3em;
    }
    select:focus { outline: none; }
    select option {
        color: white;
        background-color: black;
    }
    #loader {
        display:none;
        position: relative;
        /*
        position:fixed;
        top:10px;
        left:10px;
        */
        margin: 10px;
        border: 0.2em solid #f3f3f3;
        border-top: 0.2em solid #333333;
        border-radius: 50%;
        width: 1em;
        height: 1em;
        animation: spin 1s linear infinite;
        z-index: 10;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    #content {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
    }
    .photo,video,iframe {
        display:block;
        position: relative;
    }
    .video-p::-webkit-media-controls-panel {
        display: flex !important;
        opacity: 1 !important;
    }
    #error-icon, #unmute-icon, #mute-icon {
        fill:grey;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
    }
    @media screen and (max-width: 800px) {
        .header-container {
            line-height: initial;
        }
    }
    @media screen and (max-width: 1000px) {
        select {
            padding-left: 0;
        }
    }
  </style>
</head>
    <body>
        <div id="header">
            <div id="title"></div>
            <div class="row">
                <div id="subreddit" class="header-container with-dot"></div>
                <div id="author" class="header-container with-dot"></div>
                <div id="locked" class="header-container with-dot">&#128274;</div>
                <div id="flair" class="header-container with-dot"></div>
                <div id="domain" class="header-container with-dot"></div>
                <div id="date" class="header-container"></div>
            </div>
            <div class="row">
                <div id="score" class="header-container with-dot"></div>
                <div id="num_comments" class="header-container"><span>0</span> comments</div>
                <div id="nsfw" class="header-container">&#128286;</div>
                <div id="all_awardings" class="header-container"></div>
                <div id="total_awards_received" class="header-container"><span>0</span> awards</div>
            </div>
        </div>
        <div id="messages"></div>
        <div id="loader"></div>
        <div id="content"></div>
        <div id="footer"></div>
<svg style="display:none" id="svg-icons">
  <svg id="error-icon">
    <svg viewBox="0 0 253 253" x="0px" y="0px" width="100%" height="100%">
      <polygon points="86,127 0,41 41,0 127,86 213,0 253,41 167,127 253,213 213,253 127,167 41,253 0,213 "/>
    </svg>
  </svg>
  <svg id="mute-icon" class="volume-icon">
    <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
      <path d="M70.564,57.797c0.538-0.797,0.988-1.616,1.339-2.445  c0.374-0.885,0.663-1.809,0.855-2.76c0.18-0.892,0.275-1.861,0.275-2.902c0-1.04-0.096-2.009-0.275-2.901  c-0.192-0.951-0.481-1.875-0.855-2.76c-0.351-0.829-0.801-1.648-1.339-2.445c-0.517-0.769-1.116-1.493-1.785-2.162  c-1.332-1.332-2.895-2.4-4.608-3.125c-1.758-0.741-2.582-2.766-1.842-4.523c0.74-1.758,2.766-2.583,4.524-1.843  c2.59,1.096,4.905,2.663,6.829,4.587c0.966,0.965,1.847,2.035,2.626,3.191c0.748,1.109,1.407,2.327,1.961,3.639  c0.549,1.296,0.975,2.663,1.262,4.087c0.286,1.421,0.437,2.846,0.437,4.256c0,1.411-0.15,2.836-0.437,4.258  c-0.287,1.423-0.713,2.79-1.262,4.086c-0.554,1.312-1.213,2.529-1.961,3.638c-0.219,0.325-0.445,0.644-0.68,0.953  c-0.809,1.071-0.966,0.67-1.884-0.249c-1.096-1.096-1.619-1.619-2.714-2.714C70.203,58.833,69.931,58.74,70.564,57.797z"/>
      <path d="M80.397,67.533c0.736-0.845,1.417-1.73,2.04-2.653  c0.989-1.47,1.829-3.006,2.5-4.591c0.7-1.653,1.239-3.384,1.601-5.173c0.346-1.709,0.526-3.522,0.526-5.427  s-0.181-3.717-0.526-5.425c-0.361-1.789-0.9-3.52-1.601-5.173c-0.671-1.585-1.511-3.122-2.5-4.591  c-0.977-1.446-2.098-2.803-3.344-4.049c-1.246-1.247-2.603-2.368-4.049-3.343c-1.469-0.99-3.007-1.83-4.592-2.501  c-1.758-0.74-2.582-2.766-1.842-4.524s2.767-2.583,4.523-1.842c2.067,0.875,4.003,1.924,5.784,3.125  c1.836,1.238,3.536,2.64,5.079,4.182c1.542,1.543,2.944,3.244,4.182,5.08c1.202,1.78,2.25,3.716,3.124,5.783  c0.874,2.065,1.551,4.24,2.008,6.501C93.763,45.146,94,47.415,94,49.689c0,2.275-0.237,4.545-0.688,6.781  c-0.457,2.261-1.134,4.436-2.008,6.501c-0.874,2.066-1.923,4.001-3.124,5.782c-0.875,1.298-1.832,2.527-2.861,3.682  c-0.813,0.911-0.86,0.651-1.708-0.195c-1.101-1.102-1.9-1.9-3.001-3.001C79.741,68.371,79.604,68.443,80.397,67.533z"/>
      <path d="M20.096,32.725h-8.13C8.685,32.725,6,35.41,6,38.691v22.484  c0,3.28,2.685,5.966,5.966,5.966h13.441c6.906,5.195,13.81,10.393,20.713,15.591c3.148,2.368,6.793,1.988,6.793-2.12  c0-4.925,0-9.442,0-14.365c0-1.745-0.261-2.222-1.506-3.468c-9.727-9.727-19.453-19.454-29.18-29.181  C21.37,32.739,21.311,32.725,20.096,32.725z"/>
      <path d="M12.589,12.588L12.589,12.588c2.116-2.115,5.577-2.115,7.692,0  l67.13,67.131c2.116,2.115,2.116,5.576,0,7.691l0,0c-2.115,2.116-5.576,2.116-7.691,0l-67.131-67.13  C10.474,18.165,10.474,14.704,12.589,12.588z"/>
      <path d="M46.121,17.134c-2.872,2.163-5.744,4.325-8.617,6.487  c-1.585,1.193-0.805,1.707,0.599,3.11c4.504,4.506,9.009,9.011,13.514,13.516c1.004,1.016,1.297,0.896,1.297-0.191v-20.8  C52.914,15.147,49.269,14.766,46.121,17.134z"/>
    </svg>
  </svg>
  <svg id="unmute-icon" class="volume-icon">
    <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
      <path d="M66.853,69.518c-1.759,0.74-3.783-0.085-4.524-1.843c-0.74-1.759,0.086-3.783,1.844-4.522  c0.828-0.353,1.647-0.803,2.445-1.34c0.769-0.518,1.491-1.117,2.162-1.785c0.668-0.67,1.27-1.395,1.785-2.162  c0.538-0.797,0.988-1.617,1.339-2.446c0.374-0.886,0.663-1.808,0.854-2.761c0.181-0.893,0.275-1.86,0.275-2.9  s-0.096-2.01-0.275-2.901c-0.191-0.952-0.48-1.875-0.854-2.76c-0.351-0.829-0.801-1.649-1.339-2.445  c-0.518-0.768-1.117-1.492-1.785-2.162c-1.332-1.331-2.895-2.399-4.607-3.125c-1.758-0.74-2.584-2.766-1.844-4.523  c0.741-1.759,2.767-2.583,4.524-1.843c2.59,1.097,4.905,2.664,6.83,4.588c0.966,0.966,1.846,2.034,2.625,3.19  c0.748,1.109,1.407,2.326,1.962,3.638c0.548,1.297,0.975,2.664,1.262,4.088c0.286,1.42,0.438,2.845,0.438,4.256  s-0.15,2.836-0.438,4.256c-0.287,1.426-0.714,2.791-1.262,4.087c-0.555,1.312-1.214,2.53-1.962,3.64  c-0.779,1.156-1.659,2.226-2.625,3.19c-0.966,0.966-2.036,1.847-3.19,2.627C69.383,68.303,68.164,68.962,66.853,69.518z"/>
      <path d="M73.136,81.207c-1.759,0.74-3.783-0.084-4.524-1.842c-0.74-1.758,0.085-3.783,1.844-4.524  c1.584-0.67,3.121-1.511,4.59-2.501c1.447-0.975,2.805-2.098,4.051-3.344s2.367-2.603,3.343-4.049c0.99-1.469,1.83-3.006,2.501-4.59  c0.699-1.654,1.239-3.386,1.602-5.175c0.345-1.708,0.525-3.521,0.525-5.426s-0.182-3.719-0.525-5.427  c-0.361-1.787-0.901-3.52-1.602-5.173c-0.671-1.584-1.511-3.122-2.501-4.591c-0.976-1.446-2.097-2.803-3.343-4.049  s-2.604-2.367-4.051-3.343c-1.469-0.99-3.006-1.83-4.59-2.501c-1.759-0.741-2.584-2.768-1.844-4.524  c0.741-1.758,2.768-2.583,4.524-1.842c2.067,0.874,4.003,1.923,5.784,3.124c1.836,1.238,3.535,2.639,5.078,4.182  s2.944,3.244,4.183,5.079c1.2,1.782,2.25,3.717,3.124,5.784c0.874,2.063,1.55,4.24,2.007,6.5C93.764,45.214,94,47.481,94,49.757  c0,2.274-0.236,4.544-0.688,6.78c-0.457,2.261-1.133,4.437-2.007,6.5c-0.874,2.066-1.924,4.002-3.124,5.783  c-1.237,1.836-2.64,3.537-4.183,5.08c-1.543,1.541-3.244,2.943-5.08,4.182C77.138,79.283,75.203,80.332,73.136,81.207z"/>
      <path d="M46.121,17.202c-6.903,5.197-13.808,10.396-20.712,15.592H11.966C8.686,32.794,6,35.479,6,38.759v22.483  c0,3.281,2.686,5.965,5.966,5.965h13.442c6.904,5.197,13.81,10.395,20.712,15.592c3.147,2.367,6.793,1.988,6.793-2.121  c0-20.451,0-40.903,0-61.354C52.914,15.214,49.269,14.833,46.121,17.202z"/>
    </svg>
  </svg>
</svg>
    </body>
</html>
