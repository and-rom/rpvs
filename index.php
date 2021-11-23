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
            } catch (League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
                $response->code = 204;
                $response->msg = $e->getMessage();
            }
        }

    $options['raw_json'] = 1;
    $options['limit'] = 20;
    switch ($action) {
        case "feed":
        case "sr":
        case "mr":
        case "u":
            $path = isset($_GET['path']) ? $_GET['path'] : "/";
            $sort = isset($_GET['sort']) ? $_GET['sort'] : "";
            $options['t'] = isset($_GET['sortPeriod']) ? $_GET['sortPeriod'] : "";
            $options['after'] = isset($_GET['after']) ? $_GET['after'] : "";
            $apiRequest = $provider->getAuthenticatedRequest(
                'GET',
                'https://oauth.reddit.com' . $path . $sort . '?' . http_build_query($options),
                $oauth2token
            );
            $apiResponse = $provider->getResponse($apiRequest);
            $content = (string) $apiResponse->getBody();
            $apiResponse = json_decode($content);
            $response->posts = [];
            foreach ($apiResponse->data->children as $post) {
                $last_name = $post->data->name;
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
                            } elseif (isset($post->data->preview->images[0]->source->url)) {
                                $obj->type = "photo";
                                $preview = 1;
                            } else{
                                $obj->type = "link";
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
                }

                if ((isset($_GET['type']) && $_GET['type'] != 'all' && $_GET['type'] != $obj->type) || $obj->type == "unknown" || $obj->type == "link")
                    continue;
                $obj->name = $post->data->name;
                $obj->title = isset($post->data->title) ? $post->data->title : "" ;
                $obj->subreddit = $post->data->subreddit;
                $obj->url = '/'.$post->data->subreddit_name_prefixed.'/';
                $obj->parent = isset($post->data->crosspost_parent) ? $post->data->crosspost_parent_list[0]->subreddit : "" ;
                $obj->parent_url = isset($post->data->crosspost_parent) ? '/'.$post->data->crosspost_parent_list[0]->subreddit_name_prefixed.'/' : "" ;
                $obj->author = $post->data->author;
                $obj->author_url = '/user/'.$post->data->author.'/submitted/';
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

                $obj->preview = isset($post->data->preview->images[0]->source->url) ? $post->data->preview->images[0]->source->url : "";

                switch ($obj->type) {
                    case "photo":
                        $obj->src = !isset($preview) ? $post->data->url : $post->data->preview->images[0]->source->url;
                        $response->posts[] = clone $obj;
                        break;
                    case "video":
                        if (isset($post->data->preview->reddit_video_preview->fallback_url)) {
                            $obj->src = $post->data->preview->reddit_video_preview->fallback_url;
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

            $response->after = isset($apiResponse->data->after) ? $apiResponse->data->after : isset($last_name) ? $last_name : "";
            $response->code = 200;
            break;
        case "srs":
            $response->s = [];
            $options['after'] = '';
            do {
                $apiRequest = $provider->getAuthenticatedRequest(
                    'GET',
                    'https://oauth.reddit.com/subreddits/mine/subscriber?' . http_build_query($options),
                    $oauth2token
                );
                $apiResponse = $provider->getResponse($apiRequest);
                $content = (string) $apiResponse->getBody();
                $apiResponse = json_decode($content);
                foreach ($apiResponse->data->children as $s) {
                    $obj = new stdClass;
                    $obj->subreddit = $s->data->display_name;
                    $obj->url = $s->data->url;
                    $obj->icon = $s->data->community_icon;
                    $response->s[] = clone $obj;
                }
                $options['after'] = $apiResponse->data->after;
            } while ($options['after'] != '');
            usort($response->s, function($a, $b) {
                return strcmp(strtolower($a->subreddit), strtolower($b->subreddit));
            });
            $response->code = 200;
            break;
        case "mrs":
            $response->m = [];
            $apiRequest = $provider->getAuthenticatedRequest(
                'GET',
                'https://oauth.reddit.com/api/multi/mine?' . http_build_query($options),
                $oauth2token
            );
            $apiResponse = $provider->getResponse($apiRequest);
            $content = (string) $apiResponse->getBody();
            $apiResponse = json_decode($content);

            foreach ($apiResponse as $m) {
                $obj = new stdClass;
                $obj->multireddit = $m->data->display_name;
                $obj->url = $m->data->path;
                $obj->icon = $m->data->icon_url;
                $response->m[] = clone $obj;
            }
            usort($response->m, function($a, $b) {
                return strcmp(strtolower($a->multireddit), strtolower($b->multireddit));
            });
            $response->code = 200;
            break;
        default:
            $response->msg = "Method Not Allowed";
            $response->code = 405;
            break;
    }

    } else if(isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['oauth2state'])) {
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
            $(document).ready(function () {
                var currentLayout;

                var layouts = [];

                var layout$ = {
                    // Properties
                    layoutType: "feed",
                    path: "/",
                    sort: "new",
                    sortPeriod: "",
                    type: "all",
                    after: "",
                    currentSlide: 0,
                    slides: [],
                    noMore: false,
                    iframe: null,
                    locked: false,
                    wasHidden: false,
                    updateLocked: false,
                    // Methods
                    save: function () {
                        Cookies.set("layoutType", this.layoutType, { expires: 0.5 });
                        Cookies.set("path", this.path, { expires: 0.5 });
                        Cookies.set("sort", this.sort, { expires: 0.5 });
                        Cookies.set("sortPeriod", this.sortPeriod, { expires: 0.5 });
                        Cookies.set("type", this.type, { expires: 0.5 });
                    },
                    update: function (restore = false) {
                        if (this.updateLocked) {
                            return;
                        }
                        $("#loader").show();

                        $.ajax({
                            dataType: "json",
                            url: "./",
                            async: true,
                            data: {
                                action:     this.layoutType,
                                path:       this.path,
                                sort:       this.sort,
                                sortPeriod: this.sortPeriod,
                                type:       this.type,
                                after:      this.after,
                            },
                            context: this,
                            success: restore ? this.restore : this.response,
                            error: this.error
                        });
                    },
                    restore: function (data) {
                        this.response(data);
                        setMessage("Restored");
                    },
                    response: function (data) {
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
                                this.clearPostInfo();*/
                                break;
                            case 511:
                                $("#loader").hide();
                                setMessage(data.msg);
                                if (data.hasOwnProperty('auth_url')) {
                                    setTimeout(function () {
                                        window.location.href = data.auth_url;
                                    }, 1500)
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
                    error: function (jqXHR, textStatus, errorThrown) {
                        setMessage("Update error!");
                        this.updateLocked = false;
                    },
                    lock: function () {
                        $("#loader").show();
                        this.locked = true;
                    },
                    unlock: function (loader = true) {
                        if (!this.updateLocked && loader) $("#loader").hide();
                        this.locked = false;
                    },
                    checkHidden: function () {
                        if ($('body').is(":visible")) {
                            this.wasHidden = false;
                        } else {
                            this.wasHidden = true;
                        }
                    },
                    display: function () {
                        this.lock();
                        if (this.slides.length == 0) {
                            this.unlock();
                            this.clearPostInfo(true);
                            return;
                        }
                        if (this.slides[this.currentSlide].type == "photo") {
                            this.displayPhoto();
                        } else {
                            this.displayVideo();
                        }
                        this.clearPostInfo();
                        this.displayPostInfo();
                        if (this.currentSlide - 1 < 0) {
                            Cookies.set("after", "", { expires: 0.5 });
                        } else {
                            Cookies.set("after", this.slides[this.currentSlide - 1].name, { expires: 0.5 });
                        }
                    },
                    displayPostInfo: function () {
                        switch (this.layoutType) {
                            case "feed":
                                $("#layout").html("Front Page");
                                break;
                            case "sr":
                                $("#layout").html(this.slides[this.currentSlide].subreddit);
                                break;
                            case "mr":
                                $("#layout").html(this.path.split("/").at(-2));
                                break;
                            case "u":
                                $("#layout").html(this.slides[this.currentSlide].author);
                                break;
                        }
                        $("#sort").html(this.sort);
                        $("#title").html(this.slides[this.currentSlide].title).show();
                        $("#subreddit").html(this.slides[this.currentSlide].subreddit).attr('data-url', this.slides[this.currentSlide].url).show();
                        if (this.slides[this.currentSlide].parent != "") {
                            $("#parent span").html(this.slides[this.currentSlide].parent)
                            $("#parent").attr('data-url', this.slides[this.currentSlide].parent_url).show();
                        }
                        $("#author").html(this.slides[this.currentSlide].author).attr('data-url', this.slides[this.currentSlide].author_url).show();
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
                        $("#score").html(this.slides[this.currentSlide].score > 1000 ? Math.round(this.slides[this.currentSlide].score / 100) / 10 + "K" : this.slides[this.currentSlide].score).show();
                        $("#num_comments span").html(this.slides[this.currentSlide].num_comments);
                        $("#num_comments").show();
                        $("#nsfw").toggle(this.slides[this.currentSlide].over_18);
                        if (this.slides[this.currentSlide].all_awardings.length > 0) {
                            this.slides[this.currentSlide].all_awardings.forEach((award) => {
                                $('<img />', { src: award }).appendTo("#all_awardings")

                            });
                            $("#all_awardings").show();
                        }
                        if (this.slides[this.currentSlide].total_awards_received > 0) {
                            $("#total_awards_received span").html(this.slides[this.currentSlide].total_awards_received); $("#total_awards_received").show();
                        }
                    },
                    clearPostInfo: function (partial = false) {
                        $("#title").empty().hide();
                        $("#subreddit").empty().hide().removeData();
                        $("#parent span").empty(); $("#parent").hide().removeData();
                        $("#author").empty().hide().removeData();
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
                    displayPhoto: function () {
                        this.iframe = new Image();
                        this.iframe.src = this.slides[this.currentSlide].src;
                        var _this = this;
                        this.iframe.onerror = function () {
                            $('#content').empty().append($("#error-icon").clone());
                            setMessage("Load error");
                            _this.unlock();
                        };
                        this.iframe.onload = function () {
                            $('#content').empty();
                            $(this).appendTo('#content').attr('id', "photo").addClass("photo");
                            _this.resize();
                            _this.unlock();
                        };
                    },
                    displayVideo: function () {
                        $('#content').empty().append($('<video />', {
                            id: 'video',
                            src: this.slides[this.currentSlide].src,
                            type: 'video/mp4',
                            poster: this.slides[this.currentSlide].preview,
                            loop: ''
                        }));
                        this.iframe = $('video').first();
                        this.resize();

                        $(this.iframe).removeAttr("controls");
                        $(this.iframe).prop("controls", false);

                        $(this.iframe).prop("autoplay", true);

                        $(this.iframe).removeAttr("muted");
                        $(this.iframe).prop("muted", videoMuted);
                        $(this.iframe).prop("preload", "none");

                        var _this = this;
                        var img = new Image();
                        img.src = this.slides[this.currentSlide].preview;
                        img.onload = function () {
                            _this.unlock(false);
                        };

                        $(this.iframe).find('source').last().on('error', function (e) {
                            $("#content").empty().append($("#error-icon").clone());
                            _this.unlock();
                        });
                        $(this.iframe).on("play", function (e) {
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
                    show: function (whereTo) {
                        if (!this.locked && this.slides.length > 0) {
                            if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined' && this.slides[this.currentSlide].video_type == "tumblr") {
                                this.iframe[0].pause();
                                $(this.iframe).attr('src', '');
                                $(this.iframe).find('source').last().attr('src', '');
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
                    next: function () {
                        if (this.currentSlide + 1 > this.slides.length - 5) {
                            if (!this.noMore) this.update();
                            this.updateLocked = true;
                        }
                        if (this.currentSlide < this.slides.length - 1) {
                            this.currentSlide++;
                            return true;
                        } else {
                            return false;
                        }
                    },
                    prev: function () {
                        if (this.currentSlide > 0) {
                            this.currentSlide--;
                            return true;
                        } else {
                            return false;
                        }
                    },
                    resize: function () {
                        this.checkHidden();
                        if (this.wasHidden) {
                            return;
                        }
                        var elmt = window,
                            prop = "inner";
                        if (!("innerWidth" in window)) {
                            elmt = document.documentElement || document.body;
                            prop = "client";
                        }
                        var /*ww = elmt[prop + "Width"],
                            wh = elmt[prop + "Height"],*/
                            ww = Math.min(document.documentElement.clientWidth, window.innerWidth || 0),
                            wh = Math.min(document.documentElement.clientHeight, window.innerHeight || 0),
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
                    age: function (timestamp) {
                        var elapsed = new Date() - new Date(timestamp * 1000);

                        if (elapsed < 60000) {return 'Now';}
                        else if (elapsed < 3600000) {return Math.round(elapsed/60000) + ' m';}
                        else if (elapsed < 86400000 ) {return Math.round(elapsed/3600000) + ' h';}
                        else if (elapsed < 2592000000) {return Math.round(elapsed/86400000) + ' d';}
                        else if (elapsed < 31536000000) {return Math.round(elapsed/2592000000) + ' mo';}
                        else {return Math.round(elapsed/31536000000) + ' y';}
                    },
                    dateTime: function (timestamp) {
                        var pubDate = new Date(timestamp * 1000);

                        return pubDate.toLocaleDateString() + " " + pubDate.toLocaleTimeString();
                    },
                    seek: function (direction) {
                        if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined' && !isNaN(this.iframe[0].duration)) {
                            stepPosition = Math.round(this.iframe[0].duration * 0.05);
                            if (stepPosition < 1) stepPosition = 1;
                            var newPosition = this.iframe[0].currentTime + stepPosition * direction;
                            if (newPosition < 0)
                                newPosition = 0;
                            else
                            if (newPosition > this.iframe[0].duration)
                                newPosition = this.iframe[0].duration - 1;
                            this.iframe[0].currentTime = newPosition;
                            setMessage(formatTime(newPosition) + " / " + formatTime(this.iframe[0].duration) + " (" + (direction > 0 ? "+" : "-") + stepPosition + ")", "timer");
                        }
                    },
                    muteToggle: function () {
                        if (this.slides[this.currentSlide].type == "video" && typeof this.iframe[0] !== 'undefined' && !isNaN(this.iframe[0].duration)) {
                            videoMuted = !videoMuted;
                            this.iframe[0].muted = videoMuted;

                            $("#content svg.volume-icon").remove();
                            $("#content").append($("#" + (this.iframe[0].muted ? "mute-icon" : "unmute-icon")).clone());

                            setTimeout(function () {
                                $("#content svg.volume-icon").remove();
                            }, 800);
                        }
                    },
                    test: function () {
                        console.log("this.updateLocked: " + this.updateLocked);
                        console.log("this.locked: " + this.locked);
                        console.log("this.layoutType: " + this.layoutType);
                        console.log("this.path: " + this.path);
                        console.log("this.sort: " + this.sort);
                        console.log("this.sortPeriod: " + this.sortPeriod);
                        console.log("this.type: " + this.type);
                        console.log("this.after: " + this.after);
                        console.log("this.currentSlide: " + this.currentSlide);
                        console.log("this.slides.length: " + this.slides.length);
                        console.log("this.slides");
                        console.log(this.slides);
                        console.log(this.debug_request);
                        console.log(this.debug_content);
                    }
                };

                window.__igEmbedLoaded = function (loadedItem) {
                    console.log("??");
                };

                var messageTimer;
                function setMessage(text, id = "") {
                    if (id == "") {
                        $("#messages").append($("<span> </span>").html(text));
                        setTimeout(function () {
                            $("#messages span").first().remove();
                        }, 5000);
                    } else {
                        if (!$('#messages span#' + id).length)
                            $("#messages").append($("<span id=" + id + "> </span>").html(text));
                        else
                            $("#messages span#" + id).html(text);

                        if (messageTimer) {
                            clearTimeout(messageTimer);
                            messageTimer = 0;
                        }
                        messageTimer = setTimeout(function () {
                            $("#messages span#" + id).first().remove();
                        }, 1000);
                    }
                }

                function formatTime(seconds) {
                    var hh = Math.floor(seconds / 3600);
                    var mm = Math.floor(seconds % 3600 / 60);
                    var ss = Math.round(seconds % 3600 % 60);
                    return (hh > 0 ? (hh < 10 ? "0" : "") + hh + ":" : "") + (mm < 10 ? "0" : "") + mm + ":" + (ss < 10 ? "0" : "") + ss;
                }

                layouts.push({
                    __proto__: layout$
                });

                currentLayout = layouts[0];

                currentLayout.update();

                $(document).one('auth', function (e) {
                    if (typeof Cookies.get("after") !== 'undefined') {
                        if (confirm('Do you want to restore dash?')) {
                            $('#content').empty();
                            currentLayout = {
                                __proto__: layout$,
                                layoutType: Cookies.get("layoutType"),
                                path: Cookies.get("path"),
                                sort: Cookies.get("sort"),
                                sortPeriod: Cookies.get("sortPeriod"),
                                type: Cookies.get("type"),
                                after: Cookies.get("after")
                            }
                            if (Cookies.get("layoutType") == "feed") {
                                layouts.splice(0, 0, currentLayout)
                            } else {
                                layouts.push(currentLayout);
                            }
                            currentLayout.update(true);
                            $("#back").toggle(currentLayout.layoutType != "feed");
                        }
                    }
                    currentLayout.save();
                    var ph = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAACXBIWXMAAA7DAAAOwwHHb6hkAAAAGXRFWHRTb2Z0d2FyZQB3d3cuaW5rc2NhcGUub3Jnm+48GgAABGZJREFUeJztm01sVFUUx3/33MICB6QIxKWtoRihCiQukbjRxLjx25igMUZDYqLCQncCSwshasKGiGGB1cQ1MVU3tnWNtIWE1qgxEss4dUpqE43OPBf3PZwOM/M+7nv3vlp/yUk6ze0953/e67z77j1HUTzbQO+HYBjYCWoIgi3AZqASjvkdWAS1AMEsMAtqChoTQM1BjLmzF+QkyDRIEyTIaE2QKZATwB7fouKogBwBuWwhOM5mQA7z751TCvpBjoMsFCi83WogxzD/Rt5QoF8AmXcovFMi3gDEtfhB0JMehbeZngAGHGnXT4D85l/0LXYD9HNFKheQUyUQGmcnAZW3+PUgH5dAXFI7D6zLUby6UAJRKU1dMLHboUDO+ReT2T6B3k8I3Vu/nAIOZUjcNeAzCH4ENQj0xYyvQzAOahK4AsEPwM+gGphnfdbH3G5QFQi+yPC3+plsWdffAP0tEw2AzHUfqx+jd4I2gT4IMpv9TtBPp1U/CLKYzRn7Osy3C6TRMm4J9Muk+7beYLH2WDSakqEsHNV7TPt5OOYnYHcK4a0MW9wFkyRLuH7J4ktnpvu8cgykCtydUXw0T9UiCS/Gzd4Pct0iAVPdp9YHzSrSFvneIr55er9AyXGLyWMSwG324tkC8rdljEe7TV7B/pW2VwI6sQn0K6C+BPkOZAnkKsg50I+ycjW3HuS8ZXwBSI3O+wlyJIfJ0yTgQZBrMfNVQUZBTocJso0vssOdEpDHTk7SBBwA+StHQWnt5pd1tMLaB9yb4urZ0A/yKfGrwyLZRbjHGCZAnnfnW14H7nTnrxtGc3QHPOzIqwZec+QrjkfAJGAb2VdmaXkg9FcGhoGtYg4t8t9B6YwccOMnEQr0foHgPodO73HoKwHBsAA73DlUlu8BuTMk5qzOFYHXg4xbUTsEgu0OPW5w6CsBwXbB7Tmb9SZlzmx0nYCysdH5mVrZEExxwlpl6f8EgKr6jsIfqiphTc4aJZgVYM53GB6ZE1ONtVZR0wKNr4Gm71A8EEBjQoAF4LLvaDwwBdSihdCYz0g8MQY3t8Saoz4j8YPRHN0BF4Ee53r/Oa4Al4AVhQcf+YnFC2eiH1oS0DzDKi1MTskCNM9GH1rvgGXgtPt4nPM+Le8/ba/DzfeA627jcco8ND9o/UX7fsAiqLcdBuQY9RZwI3aUqb3NfPTcjT6QP/wdiOpxUpx/DIDUszlibxf9D3k8Da4DdyUVH6KfyujsYgdnOzGFD76u/pMpxUfIiYxOl0GNgXwI6iuQPz1e/ZGM4oHVXyo7CvbNFOtWcbF0bhXjfSBn/YtKbLmWy0coTCucb3FxNkKxx/36ccrbMvNsgcJXMFCypqlx0j/nrSlD29wvoF8F921zrWwGOYrp4XMl/FeQd4DbfQpvpwLyJqa9tSjh05hGyTzqjgtlD8gIyCVWNkqktQbItyDvAvcXEaiL6rCtLe3zQ2H7/B2YtproSi4DdVA1COaAq6BmoDGO2bYvjH8AJnU6lSYCwysAAAAASUVORK5CYII="
                    $.ajax({
                        dataType: "json",
                        url: "./",
                        async: true,
                        data: { action: "mrs" },
                        beforeSend: function (data) { $("#loader").show(); },
                        success: function (data) {
                            $("#loader").hide();

                            $.each(data.m, function (i, obj) {
                                $("ul#m").append(
                                    $("<li />", { 'data-url': obj.url }).append(
                                        $('<img />', {
                                            src: obj.icon
                                        }), $('<span />').addClass('title').html(obj.multireddit)).addClass('multireddit'));
                            });
                        }
                    });
                    $.ajax({
                        dataType: "json",
                        url: "./",
                        async: true,
                        data: { action: "srs" },
                        beforeSend: function (data) { $("#loader").show(); },
                        success: function (data) {
                            $("#loader").hide();

                            $.each(data.s, function (i, obj) {
                                $("ul#s").append(
                                    $("<li />", { 'data-url': obj.url }).append(
                                        $('<span />').addClass('icon-back').append(
                                            $('<img />', {
                                                src: obj.icon != '' ? obj.icon : ph
                                            }).toggleClass("random" + (Math.floor(Math.random() * (8)) + 1), obj.icon == '')
                                            .on("error", function () {
                                                $(this).attr("src", ph).addClass("random" + (Math.floor(Math.random() * (8)) + 1));
                                            })), $('<span />').addClass('title').html(obj.subreddit)).addClass('subreddit'));
                            });
                        }
                    });
                });

                $(window).resize(function (e) {
                    currentLayout.resize()
                });

                $("#content").on('click', function (e) {
                    $(".header").toggle();
                    $("#sidebar").hide();
                });
                $(document).on('click', '.subreddit, .multireddit, #author', function (e) {
                    layouts.push({
                        __proto__: layout$,
                        layoutType: this.id == "author" ? "u" : $(this).hasClass("multireddit") ? "mr" : "sr",
                        path: $(this).data("url")
                    });
                    currentLayout = layouts[layouts.length - 1];
                    currentLayout.update();
                    currentLayout.save();
                    $("#sidebar").hide();
                    $("#back").show();
                    if (layouts.length > 2) $("#home").show();
                });
                $("#home, #back, #sidebar-home").on('click', function (e) {
                    if (layouts.length <= 1) return;
                    switch (this.id) {
                        case 'home':
                        case 'sidebar-home':
                            while (layouts.length > 1) {
                                layouts.pop();
                            }
                            break;
                        case 'back':
                            layouts.pop();
                            break;
                    }

                    currentLayout = layouts[layouts.length - 1];
                    $("#type").val(currentLayout.type);
                    currentLayout.save();
                    currentLayout.display();
                    if (layouts.length < 3) $("#home").hide();
                    if (layouts.length == 1) $("#back").hide();
                });
                $("#layout").on('click', function (e) {
                    $("#sidebar").toggle();
                });
                $("#sort").on('click', function (e) {
                    $("#sort-menu").toggle();
                    $("#sort-order").show();
                    $("#sort-period").hide();
                });
                $("#sort-order li").on('click', function (e) {
                    currentLayout.sort = this.id.split("-")[1];
                    currentLayout.after = "";
                    currentLayout.currentSlide = 0;
                    currentLayout.slides = [];
                    switch (this.id) {
                        case "sort-best":
                        case "sort-hot":
                        case "sort-new":
                        case "sort-rising":
                            $("#sort-menu").hide();
                            currentLayout.update();
                            currentLayout.save();
                            break;
                        case "sort-controversial":
                        case "sort-top":
                            $("#sort-order").hide();
                            $("#sort-period").show();
                            break;
                    }
                });
                $("#sort-period li").on('click', function (e) {
                    currentLayout.sortPeriod = this.id.split("-")[1];
                    $("#sort-menu").hide();
                    currentLayout.update();
                    currentLayout.save();
                });
                $("#close").on('click', function (e) {
                    $("#sidebar").hide();
                });
                $("#fullscreen").on('click', function (e) {
                    var requestFullScreen = document.documentElement.requestFullscreen || document.documentElement.mozRequestFullScreen || document.documentElement.webkitRequestFullScreen || document.documentElement.msRequestFullscreen;
                    var cancelFullScreen = document.exitFullscreen || document.mozCancelFullScreen || document.webkitExitFullscreen || document.msExitFullscreen;
                    if (!document.fullscreenElement && !document.mozFullScreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement)
                        requestFullScreen.call(document.documentElement);
                    else
                        cancelFullScreen.call(document);
                });
                var timer;
                var hided = false;
                $(window).on("mousemove", function () {
                    if (!hided) {
                        if (timer) {
                            clearTimeout(timer);
                            timer = 0;
                        }
                    } else {
                        $('html').css({ cursor: '' });
                        hided = false;
                    }
                    timer = setTimeout(function () {
                        $('html').css({ cursor: 'none' });
                        hided = true;
                    }, 2000);
                });
                var stealthMode = !(navigator.userAgent.match(/Android/i) ||
                    navigator.userAgent.match(/webOS/i) ||
                    navigator.userAgent.match(/iPhone/i) ||
                    navigator.userAgent.match(/iPad/i) ||
                    navigator.userAgent.match(/iPod/i) ||
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

                $("#search").keyup(function () {
                    $('.sidebar-container li.multireddit, .sidebar-container li.subreddit').each((idx, li) => {
                        $(li).toggle($(li).text().indexOf($(this).val()) !== -1);
                    });
                });

                // keybindings
                var enabled = true;
                $(document).on('keydown', function (e) {
                    var code = (e.keyCode ? e.keyCode : e.which);
                    if (e.altKey && code == 81) { // Alt + 'q'
                        enabled = !enabled;
                        setMessage("Hot keys " + (enabled ? "enabled" : "disabled"));
                        return;
                    }

                    if (enabled && !$("input").is(":focus")) switch (code) {
                        case 37: // left
                        case 65: // 'a'
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
                                keyHidden = $('body').is(":hidden");
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
                $("#content").on('mousewheel DOMMouseScroll', function (e) {
                    e.stopPropagation();
                    currentLayout.show(parseInt(e.originalEvent.wheelDelta || -e.originalEvent.detail));
                });
                // touch
                var xDown, yDown, xUp, yUp = null;
                var xDiffPrev = 0;
                var touchOff = false;
                var mouseButtonDown = false;
                $("#content").bind('touchstart', function (ev) {
                    ev.stopPropagation();
                    if (touchOff) { return; }
                    var e = ev.originalEvent;
                    xDown = e.touches[0].clientX;
                    yDown = e.touches[0].clientY;
                });
                $("#content").mousedown(function (ev) {
                    var e = ev.originalEvent;
                    if (e.which == 2) {
                        mouseButtonDown = true;
                        xDown = e.clientX;
                        yDown = e.clientY;
                    }
                });
                $("#content").bind('touchmove', function (ev) {
                    ev.stopPropagation();
                    if (touchOff) { return; }
                    var e = ev.originalEvent;
                    if (!xDown || !yDown) { return; }
                    xUp = e.touches[0].clientX;
                    yUp = e.touches[0].clientY;
                    var xDiff = xDown - xUp;
                    var yDiff = yDown - yUp;
                    var direction = 0;
                    if (Math.abs(xDiff) > Math.abs(yDiff)) {
                        if (xDiff > 0) {
                            direction = 1;
                        } else if (xDiff < 0) {
                            direction = -1;
                        }
                        if (Math.abs(xDiff - xDiffPrev) > 30) {
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
                $("#content").mousemove(function (ev) {
                    if (!mouseButtonDown) { return; }
                    if (!xDown || !yDown) { return; }
                    var e = ev.originalEvent;
                    xUp = e.clientX;
                    yUp = e.clientY;
                    var xDiff = xDown - xUp;
                    var yDiff = yDown - yUp;
                    var direction = 0;
                    if (Math.abs(xDiff) > Math.abs(yDiff)) {
                        if (xDiff > 0) {
                            direction = 1;
                        } else if (xDiff < 0) {
                            direction = -1;
                        }
                        if (Math.abs(xDiff - xDiffPrev) > 30) {
                            currentLayout.seek(-direction);
                            xDiffPrev = xDiff;
                        }
                    }
                });
                $("#content").bind('touchend', function (ev) {
                    ev.stopPropagation();
                    if (touchOff) { return; }
                    if (typeof xUp == 'undefined' || !xUp || !yUp) { return; }
                    var xDiff = xDown - xUp;
                    var yDiff = yDown - yUp;
                    if (Math.abs(xDiff) > Math.abs(yDiff)) {
                        /*if ( xDiff > 25 ) {
                            //currentLayout.show(-1);
                        } else if (xDiff < -25) {
                            //currentLayout.show(1);
                        }*/
                    } else {
                        if (yDiff > 25) {
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
                $("#content").mouseup(function (ev) {
                    var e = ev.originalEvent;
                    if (e.which == 2) {
                        mouseButtonDown = false;
                        if (typeof xUp == 'undefined' || !xUp || !yUp) { return; }
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

            html,
            body {
                height: 100%;
                color: white;
                background: black;
                touch-action: none;
                font-family: sans-serif;
            }

            .button,
            .subreddit,
            .multireddit,
            #author,
            .home {
                cursor: pointer;
            }

            #subheader,
            #messages,
            #mainheader {
                    overflow-y: auto;
                    color: white;
                    background: rgba(40, 40, 40, .5);
                    text-shadow: 1px 1px 3px black, -1px -1px 3px black, -1px 1px 3px black, 1px -1px 3px black;
                    z-index: 1;
            }

            #mainheader {
                min-height: 4ex;
                max-height: 50%;
                width: calc(100% - 20px);
                padding: 5px 10px 0;
                position: relative;
            }

            #mainheader-container {
                float: left;
            }

            #mainheader-container span {
                display: block;
                cursor: pointer;
            }

            #mainheader-container span::after {
                content: '\25BC';
                margin-left: 5px;
                color: white;
                font-size: x-small;
            }

            #layout {
                font-size: large;
            }

            #sort {
                color: darkgray;
                text-transform: capitalize;
            }

            #mainheader::-webkit-scrollbar,
            #subheader::-webkit-scrollbar,
            #messages::-webkit-scrollbar {
                display: none;
            }

            #messages {
                max-height: 10%;
                position: relative;
                text-align: center;
            }

            #messages span {
                display: block;
            }

            #subheader {
                min-height: 50px;
                max-height: 50%;
                width: calc(100% - 20px);
                padding: 5px 10px 0;
                position: relative;
                font-size: 0;
                color: darkgray;
            }

            .subheader-container {
                display: inline-block;
                font-size: initial;
            }

            .subheader-container.with-dot::after {
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
                font-size: large;
                color: white;
            }

            #subreddit,
            #parent {
                color: dodgerblue;
            }

            #parent-icon {
                width: 1rem;
                height: 1rem;
                fill: green;
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
                width: 16px;
                height: 16px;
                vertical-align: middle;
            }

            #total_awards_received {
                margin-left: 2px;
                font-size: small;
            }

            #buttons-left {
                float: left;
                margin-top: 5px;
                height: 2rem;
                width: 5rem;
                font-size: 0;
            }

            #buttons-left .button {
                margin-right: 0.5rem;
            }

            #buttons-right {
                float: right;
                margin-top: 5px;
                font-size: 0;
            }

            #buttons-right .button {
                margin-right: 0.5rem;
            }

            #buttons-right .button:last-child {
                margin-right: initial;
            }

            #back,
            #home {
                display: none;
            }

            #sidebar {
                display: none;
                width: 300px;
                background-color: #000;
                position: fixed;
                top: 0;
                height: 100%;
                overflow: hidden;
                z-index: 2;
            }

            .sidebar-container {
                overflow: auto;
                height: calc(100% - 2rem);
            }

            .sidebar-container::-webkit-scrollbar {
                width: 5px;
            }

            .sidebar-container::-webkit-scrollbar-thumb {
                background: darkgray;
                -webkit-border-radius: 5px;
                border-radius: 5px;
            }

            #search {
                width: calc(100% - 22px - 1rem);
                margin: 5px 10px 11px;
                background-color: transparent;
                color: white;
                font-size: large;
                border: 1px solid darkgray;
                border-radius: 5px;
                padding: 0.5rem;
            }

            .sidebar-container ul:last-child {
                border: none;
            }

            .sidebar-container ul {
                margin: 0 10px;
                list-style: none;
                border-bottom: 1px solid darkgray;
            }

            .sidebar-container li {
                margin: 10px 0 10px 10px;
            }

            #sidebar img,
            #sidebar svg {
                width: 2rem;
                height: 2rem;
                object-fit: cover;
                border-radius: 50%;
                vertical-align: middle;
            }

            .random1 {/*#e17076*/ filter: invert(58%) sepia(25%) saturate(1288%) hue-rotate(308deg) brightness(94%) contrast(87%);}
            .random2 {/*#7bc862*/ filter: invert(70%) sepia(48%) saturate(424%) hue-rotate(60deg) brightness(92%) contrast(91%);}
            .random3 {/*#e5ca77*/ filter: invert(91%) sepia(72%) saturate(550%) hue-rotate(322deg) brightness(94%) contrast(91%);}
            .random4 {/*#65aadd*/ filter: invert(64%) sepia(39%) saturate(564%) hue-rotate(165deg) brightness(93%) contrast(85%);}
            .random5 {/*#a695e7*/ filter: invert(60%) sepia(74%) saturate(503%) hue-rotate(207deg) brightness(96%) contrast(89%);}
            .random6 {/*#ee7aae*/ filter: invert(71%) sepia(36%) saturate(2198%) hue-rotate(292deg) brightness(99%) contrast(88%);}
            .random7 {/*#6ec9cb*/ filter: invert(95%) sepia(93%) saturate(1399%) hue-rotate(154deg) brightness(90%) contrast(75%);}
            .random8 {/*#faa774*/ filter: invert(64%) sepia(33%) saturate(590%) hue-rotate(337deg) brightness(106%) contrast(96%);}

            .icon-back {
                display: inline-block;
                background-color: white;
                border-radius: 50%;
            }

            .sidebar-container .title {
                margin-left: 10px;
            }

            .svg-icon {
                fill: currentColor;
                height: 2rem;
                width: 2rem;
                vertical-align: middle;
            }

            #sort-menu {
                position: fixed;
                left: 50%;
                top: 50%;
                transform: translate(-50%, -50%);
                width: 200px;
                padding: 10px;
                background-color: black;
                z-index: 100;
            }

            #sort-menu ul {
                list-style: none;
                margin-top: 1rem;
                margin-left: 1rem;
            }

            #sort-menu ul li {
                margin-bottom: 1rem;
                cursor: pointer;
            }

            #sort-controversial::after,
            #sort-top::after {
                content: '\23F5';
                margin-left: 1rem;
            }

            #sort-menu .svg-icon {
                margin-right: 1rem;
            }

            #loader {
                width: 100%;
                position: fixed;
                top: 0;
                left: 0;
                padding: 2px;
                z-index: 50;
            }

            #loader .loader-bar {
                position: absolute;
                top: 0;
                right: 100%;
                bottom: 0;
                left: 0;
                background: #0091ea;
                width: 0;
                animation: borealisBar 1s linear infinite;
            }

            @keyframes borealisBar {
                0% {
                    left: 0%;
                    right: 100%;
                    width: 0%;
                }
                10% {
                    left: 0%;
                    right: 75%;
                    width: 25%;
                }
                50% {
                    left: 0%;
                    right: 50%;
                    width: 50%;
                }
                90% {
                    right: 0%;
                    left: 75%;
                    width: 25%;
                }
                100% {
                    left: 100%;
                    right: 0%;
                    width: 0%;
                }
            }

            #content {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
            }

            .photo,
            video,
            iframe {
                display: block;
                position: relative;
            }

            .video-p::-webkit-media-controls-panel {
                display: flex !important;
                opacity: 1 !important;
            }

            #error-icon,
            #unmute-icon,
            #mute-icon {
                fill: grey;
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
            }

            @media screen and (max-width: 800px) {
                .subheader-container {
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
        <div id="loader">
            <div class="loader-bar"></div>
        </div>
        <div id="mainheader" class="header">
            <div id="buttons-left">
                <svg id="home" class="svg-icon button" viewBox="0 0 100 100" version="1.1" x="0px" y="0px" width="100%" height="100%">
                    <path d="M 82.916596,56.420924 51.203289,31.690592 c -0.707436,-0.551664 -1.699217,-0.551664 -2.406849,0 L 17.083328,56.420924 c -0.475538,0.370842 -0.75362,0.940118 -0.75362,1.543249 l 0,32.571429 c 0,1.080822 0.876321,1.956947 1.956947,1.956947 l 63.426614,0 c 1.080627,0 1.956948,-0.876125 1.956948,-1.956947 l 0,-32.571429 c 0,-0.603131 -0.278083,-1.172407 -0.753621,-1.543249" />
                    <path d="M 99.197477,45.621688 51.208827,7.9256021 c -0.709785,-0.557535 -1.708023,-0.557535 -2.417808,0 L 0.80237008,45.621688 c -0.40802348,0.320548 -0.67201565,0.790215 -0.73405088,1.305479 -0.06183953,0.51546 0.08356165,1.034247 0.40410959,1.44227 l 6.81389441,8.674364 c 0.3863013,0.49139 0.9602739,0.748141 1.5403131,0.748141 0.4228963,0 0.8491194,-0.136595 1.2076317,-0.4182 L 50.000021,25.979613 89.965774,57.373742 c 0.40822,0.320744 0.927006,0.466145 1.442271,0.40411 0.515459,-0.06184 0.984931,-0.326027 1.305675,-0.734051 l 6.813894,-8.674364 c 0.667506,-0.849902 0.519766,-2.080039 -0.330137,-2.747749" />
                </svg>
                <svg id="back" class="svg-icon button" x="0px" y="0px" viewBox="0 0 30 30" width="100%" height="100%" class="svg-icon">
                    <path d="M 7.9299556,14.646447 19.243664,3.3327381 c 0.19526,-0.1952605 0.511845,-0.1952619 0.707107,0 l 2.12132,2.1213203 c 0.195262,0.1952619 0.19526,0.5118463 0,0.7071068 L 13.233256,15 l 8.838835,8.838835 c 0.195262,0.195262 0.19526,0.511846 0,0.707107 l -2.12132,2.12132 c -0.195261,0.195261 -0.511845,0.195262 -0.707107,0 L 7.9299556,15.353554 c -0.1979899,-0.19799 -0.1979899,-0.509117 0,-0.707107 z"></path>
                </svg>
            </div>
            <div id="mainheader-container">
                <span id="layout"></span>
                <span id="sort"></span>
            </div>
            <div id="buttons-right">
                <svg id="fullscreen" class="svg-icon button" x="0px" y="0px" viewBox="0 0 100 100"  width="100%" height="100%">
                    <polygon style="" points="85.669,76.831 71.338,62.5 62.5,71.338 76.831,85.669 62.5,100 100,100 100,62.5 "/>
                    <polygon style="" points="37.5,71.338 28.662,62.5 14.331,76.831 0,62.5 0,100 37.5,100 23.169,85.669 "/>
                    <polygon style="" points="37.5,0 0,0 0,37.5 14.331,23.169 28.527,37.354 37.365,28.516 23.169,14.331 "/>
                    <polygon style="" points="100,0 62.5,0 76.831,14.331 62.635,28.516 71.473,37.354 85.669,23.169 100,37.5 "/>
                </svg>
            </div>
        </div>
        <div id="subheader" class="header">
            <div id="title"></div>
            <div class="row">
                <div id="subreddit" class="subheader-container with-dot subreddit" style="display: none;"></div>
                <div id="parent" class="subheader-container with-dot subreddit" style="display: none;">
                    <svg id="parent-icon" class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                        <path d="m 71.514718,69.553009 c -6.753854,-0.893227 -10.975013,-5.016394 -16.42483,-10.877021 -2.626917,-2.834206 -5.381977,-5.962386 -8.758904,-8.740059 5.261373,-4.390759 9.090567,-9.497607 13.127049,-13.202427 3.81035,-3.471149 7.164663,-5.728715 12.056685,-6.361889 V 40.07274 L 99.992462,23.629066 71.514718,7.185392 v 9.9951 c -12.591867,1.002525 -20.54046,8.962424 -26.137264,15.124562 -3.082953,3.376927 -5.792786,6.376965 -8.336788,8.26141 -2.58169,1.888215 -4.62443,2.751291 -7.586778,2.796518 -0.0038,0 -0.0075,0 -0.01131,0 H 0 v 13.19112 h 0.0038 v 0.0038 c 0,0 0.173369,-0.0038 0.516338,-0.0038 h 28.933781 c 2.962348,0.04146 5.012625,0.908303 7.601854,2.800287 3.848038,2.796517 7.895828,8.219952 13.387103,13.447405 4.944785,4.741266 11.921004,9.267704 21.07941,9.972487 V 92.814608 L 100,76.397317 71.522255,59.96118 l -0.0075,9.591829 z"/>
                    </svg>
                    <span></span>
                </div>
                <div id="author" class="subheader-container with-dot" style="display: none;"></div>
                <div id="locked" class="subheader-container with-dot" style="display: none;">&#128274;</div>
                <div id="flair" class="subheader-container with-dot" style="display: none;"></div>
                <div id="domain" class="subheader-container with-dot" style="display: none;"></div>
                <div id="date" class="subheader-container" style="display: none;"></div>
            </div>
            <div class="row">
                <div id="score" class="subheader-container with-dot" style="display: none;"></div>
                <div id="num_comments" class="subheader-container" style="display: none;"><span>0</span> comments</div>
                <div id="nsfw" class="subheader-container" style="display: none;">&#128286;</div>
                <div id="all_awardings" class="subheader-container" style="display: none;"></div>
                <div id="total_awards_received" class="subheader-container" style="display: none;"><span>0</span> awards</div>
            </div>
        </div>
        <div id="messages"></div>
        <div id="content"></div>
        <div id="sidebar">
            <svg id="close" class="svg-icon button" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                <path d="M88.8,77.5L60.6,49.3l28.2-28.2c1.2-1.2,1.2-3.1,0-4.2l-8.5-8.5L50,38.7L19.6,8.3l-8.5,8.5c-1.2,1.2-1.2,3.1,0,4.2  l28.2,28.2L11.2,77.5c-1.2,1.2-1.2,3.1,0,4.2l8.5,8.5L50,59.9l30.4,30.4l8.5-8.5C90,80.6,90,78.7,88.8,77.5z"/>
            </svg>
            <div class="sidebar-container">
                <input id="search" placeholder="Search..." type="text" />
                <ul id="h">
                    <li id="sidebar-home" class="home">
                        <svg class="svg-icon" viewBox="0 0 100 100" version="1.1" x="0px" y="0px" width="100%" height="100%">
                            <path d="M 82.916596,56.420924 51.203289,31.690592 c -0.707436,-0.551664 -1.699217,-0.551664 -2.406849,0 L 17.083328,56.420924 c -0.475538,0.370842 -0.75362,0.940118 -0.75362,1.543249 l 0,32.571429 c 0,1.080822 0.876321,1.956947 1.956947,1.956947 l 63.426614,0 c 1.080627,0 1.956948,-0.876125 1.956948,-1.956947 l 0,-32.571429 c 0,-0.603131 -0.278083,-1.172407 -0.753621,-1.543249" />
                            <path d="M 99.197477,45.621688 51.208827,7.9256021 c -0.709785,-0.557535 -1.708023,-0.557535 -2.417808,0 L 0.80237008,45.621688 c -0.40802348,0.320548 -0.67201565,0.790215 -0.73405088,1.305479 -0.06183953,0.51546 0.08356165,1.034247 0.40410959,1.44227 l 6.81389441,8.674364 c 0.3863013,0.49139 0.9602739,0.748141 1.5403131,0.748141 0.4228963,0 0.8491194,-0.136595 1.2076317,-0.4182 L 50.000021,25.979613 89.965774,57.373742 c 0.40822,0.320744 0.927006,0.466145 1.442271,0.40411 0.515459,-0.06184 0.984931,-0.326027 1.305675,-0.734051 l 6.813894,-8.674364 c 0.667506,-0.849902 0.519766,-2.080039 -0.330137,-2.747749" />
                        </svg>
                        <span class="title">Front Page</span>
                    </li>
                </ul>
                <ul id="m"></ul>
                <ul id="s"></ul>
            </div>
        </div>
        <div id="sort-menu" style="display:none">
            <ul id="sort-order">
                <li id="sort-best">
                    <svg class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                        <path d="M 97.420917,5e-7 C 79.162648,0.0340405 64.989874,0.8170985 46.352237,19.454735 40.83682,24.970152 36.514449,30.869344 33.119598,36.496626 19.267826,36.6474 14.204503,37.941067 6.378837,49.064312 c -0.773326,1.099193 -0.278446,2.012271 1.06393,2.080364 3.662353,0.189683 10.839175,0.838376 17.307876,2.963807 -1.24024,3.448352 -2.000117,6.176801 -2.384348,7.703997 -0.199412,0.783054 0.133906,1.886657 0.702957,2.450845 L 35.589435,76.78351 c 0.569051,0.56905 1.672655,0.90237 2.450844,0.70295 1.532061,-0.37936 4.255646,-1.14897 7.703998,-2.384343 2.130293,6.468703 2.778987,13.655023 2.963807,17.317373 0.06809,1.33751 0.98117,1.82776 2.080363,1.05443 C 61.911692,85.65312 63.19586,80.5898 63.346634,66.733162 68.973916,63.328583 74.882606,59.01594 80.398024,53.500523 99.03566,34.862886 99.818713,20.690111 99.852759,2.4318415 99.852759,1.0894655 98.75843,5.0000001e-7 97.420917,5e-7 Z M 70.670656,19.454736 c 5.369507,0 9.727368,4.35786 9.727368,9.727367 0,5.369507 -4.357861,9.727368 -9.727368,9.727368 -5.369507,0 -9.727367,-4.357861 -9.727367,-9.727368 0,-5.369507 4.35786,-9.727367 9.727367,-9.727367 z m -54.155979,50.7647 C 9.206142,70.420908 2.575284,76.86901 0.147241,84.962479 c 1.376423,-1.47856 2.834996,-2.554649 4.075236,-3.1918 1.245103,-0.63714 2.718571,-0.36873 3.657262,0.56997 0.22373,0.22859 0.414325,0.48713 0.569963,0.77895 0.787917,1.53692 0.187784,3.41521 -1.339413,4.21772 -0.773326,0.41828 -6.714392,3.9275 -5.367151,12.66268 4.863684,-5.75374 11.88213,-3.03889 19.245748,-5.50964 7.135023,-2.38321 10.701698,-10.63414 6.621068,-18.35281 0.744143,5.28196 -4.012082,12.61525 -13.394128,10.38282 2.675026,-0.77332 5.088249,-2.49894 6.450081,-5.31015 0.520414,-1.07487 0.275178,-2.32765 -0.522466,-3.12529 -0.209139,-0.21401 -0.468358,-0.39093 -0.750452,-0.53197 -1.001918,-0.48637 -2.147164,-0.30094 -2.944808,0.37998 -0.821962,-3.793681 2.611646,-6.68696 6.28859,-6.307598 -2.075728,-1.022434 -4.175705,-1.46232 -6.222094,-1.405905 z" />
                    </svg>
                    Best
                </li>
                <li id="sort-hot">
                    <svg class="svg-icon" viewBox="0 0 100 100" x="0px" y="0px" width="100%" height="100%">
                        <path d="m 47.424068,97.936691 c 0,0 -32.897604,0.128156 -32.897604,-28.066128 0,-28.194285 29.257978,-35.883635 29.257978,-57.772652 0,-6.0105087 -1.601948,-8.650519 -5.04934,-12.097911 15.545303,0.4741766 27.912341,16.096373 27.912341,38.280149 0,4.40856 -1.601948,13.533256 -3.524285,15.455594 -1.922338,1.922337 12.495194,-8.727413 12.495194,-18.018711 0,0 9.855184,9.368192 9.855184,29.642445 C 85.473536,85.633731 63.738306,100 53.408945,100 59.893631,93.515315 64.302191,87.351019 64.302191,80.058952 c 0,-14.71229 -12.328591,-13.430732 -15.3787,-20.927849 A 7.3689607,7.3689607 0 0 1 48.474946,57.567602 7.6893502,7.6893502 0 0 1 49.88466,51.91593 l 3.357683,-4.844291 c 0,0 -19.146482,5.510701 -19.146482,26.015635 0,20.504934 10.201204,21.709599 13.328207,24.849417 z"/>
                    </svg>
                    Hot
                </li>
                <li id="sort-new">
                    <svg class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                        <path d="M 100,50.052632 C 100,45.210526 88.947368,42.157895 87.578947,37.842105 86.105263,33.315789 93.263158,24.368421 90.526316,20.684211 87.789474,16.894737 77.052632,20.894737 73.263158,18.157895 69.473684,15.421053 70,3.9473683 65.473684,2.4736841 61.157895,1.1052631 54.842105,10.578947 50,10.578947 45.157895,10.578947 38.947368,1.1052631 34.526316,2.4736841 30,3.9473683 30.526316,15.421053 26.842105,18.157895 23.052632,20.894737 12.315789,16.894737 9.5789474,20.684211 6.8421053,24.473684 13.894737,33.315789 12.526316,37.842105 11.052632,42.157895 0,45.210526 0,50.052632 0,54.894737 11.052632,57.947368 12.421053,62.263158 13.894737,66.789474 6.7368421,75.736842 9.4736842,79.421053 12.210526,83.210526 22.947368,79.210526 26.736842,81.947368 30.526316,84.684211 30,96.157895 34.526316,97.526316 38.842105,98.894737 45.157895,89.421053 50,89.421053 c 4.842105,0 11.052632,9.473684 15.473684,8.105263 4.526316,-1.473684 4,-12.842105 7.789474,-15.578948 3.789474,-2.736842 14.526316,1.263158 17.263158,-2.526315 C 93.263158,75.631579 86.210526,66.789474 87.578947,62.263158 88.947368,57.947368 100,54.894737 100,50.052632 Z"/>
                    </svg>
                    New
                </li>
                <li id="sort-rising">
                    <svg class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                        <path d="M 88.042064,36.83251 59.735457,65.142271 43.131819,48.540453 7.931819,83.738639 5e-7,75.807728 43.131819,32.675907 59.733638,49.279544 80.111277,28.901726 67.470911,16.261361 H 100 v 32.529092 z" />
                    </svg>
                    Rising
                </li>
                <li id="sort-controversial">
                    <svg class="svg-icon" viewBox="0 0 100 100" x="0px" y="0px" width="100%" height="100%">
                        <path d="M 9.375,7.3422906e-4 C 4.197344,7.3422906e-4 0,4.1980462 0,9.3757342 V 65.625734 c 0,5.177812 4.197344,9.375 9.375,9.375 h 9.375 v 21.875 c 0,1.20125 0.687332,2.293409 1.770019,2.813722 1.082719,0.520304 2.370075,0.377994 3.308106,-0.372316 L 54.223631,75.000734 H 90.625 c 5.177813,0 9.375,-4.197188 9.375,-9.375 V 9.3757342 C 100,4.1980782 95.802813,7.3422906e-4 90.625,7.3422906e-4 Z m 0,6.24999997094 h 81.25 c 1.725937,0 3.125,1.399125 3.125,3.125 V 65.625734 c 0,1.725937 -1.399063,3.125 -3.125,3.125 h -37.5 c -0.709688,0 -1.399063,0.240469 -1.953125,0.683594 L 25,90.37549 V 71.875734 c 0,-1.725938 -1.399125,-3.125 -3.125,-3.125 h -12.5 c -1.725875,0 -3.125,-1.399063 -3.125,-3.125 V 9.3757342 c 0,-1.725875 1.399094,-3.125 3.125,-3.125 z M 47.052003,18.750734 47.9187,44.452637 h 6.115725 l 0.866697,-25.701903 z m 3.924559,29.730225 c -1.222812,0 -2.21414,0.375859 -2.978515,1.123047 -0.747188,0.730312 -1.123047,1.656162 -1.123047,2.7771 0,1.120937 0.375859,2.046787 1.123047,2.7771 0.764375,0.730312 1.755703,1.092528 2.978515,1.092528 1.239688,0 2.231329,-0.362216 2.978516,-1.092528 0.764375,-0.730313 1.147463,-1.656163 1.14746,-2.7771 0,-1.138125 -0.383085,-2.077307 -1.14746,-2.807619 -0.747187,-0.730313 -1.738828,-1.092528 -2.978516,-1.092528 z" />
                    </svg>
                    Controversial
                </li>
                <li id="sort-top">
                    <svg class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                        <path d="m 39.258982,5.585703 v 83.24289 H 60.741018 V 5.585703 Z M 72.158028,36.309412 V 88.828593 H 93.637725 V 36.309412 Z M 6.3622754,49.34506 V 88.828593 H 27.841972 V 49.34506 Z M 0,90.625 v 3.789297 H 100 V 90.625 H 95.434132 70.36162 62.537425 37.462575 29.63838 4.5658683 Z" />
                    </svg>
                    Top
                </li>
            </ul>
            <ul id="sort-period" style="display:none">
                <li id="sort-hour">Hour</li>
                <li id="sort-day">Day</li>
                <li id="sort-week">Week</li>
                <li id="sort-month">Month</li>
                <li id="sort-year">Year</li>
                <li id="sort-all">All time</li>
            </ul>
        </div>
        <svg style="display:none">
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
