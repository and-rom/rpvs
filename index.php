<?php
function version($full=true) {
    $major = "1";
    $minor = "4";
    $commit = trim(exec('git rev-list HEAD | wc -l'));
    $hash = trim(exec('git log --pretty="%h" -n1 HEAD'));
    $date = new \DateTime(trim(exec('git log -n1 --pretty=%ci HEAD')));
    $date->setTimezone(new \DateTimeZone('UTC'));

    if ($full)
        return sprintf('v%s.%s.%s-%s (%s)', $major, $minor, $commit, $hash, $date->format('Y-m-d H:i:s'));
    else
        return sprintf('%s.%s.%s-%s', $major, $minor, $commit, $hash);
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
                $response->code = 401;
                $response->msg = $e->getMessage();
            }
        }

    $options['raw_json'] = 1;
    $options['limit'] = isset($_GET['limit']) ? $_GET['limit'] : 20;
    switch ($action) {
        case "feed":
        case "sr":
        case "mr":
        case "u":
            $type = isset($_GET['type']) ? $_GET['type'] : "all";
            $path = isset($_GET['path']) ? $_GET['path'] : "/";
            if ($action == "u") {
                $options['sort'] = isset($_GET['sort']) ? $_GET['sort'] : "";
                $sort = "";
            } else
                $sort = isset($_GET['sort']) ? $_GET['sort'] : "";
            $options['t'] = isset($_GET['sortPeriod']) ? $_GET['sortPeriod'] : "";
            $options['after'] = isset($_GET['after']) ? $_GET['after'] : "";
            $apiRequest = $provider->getAuthenticatedRequest(
                'GET',
                'https://oauth.reddit.com' . $path . $sort . '?' . http_build_query($options),
                $oauth2token
            );
            try {
                $apiResponse = $provider->getResponse($apiRequest);
            } catch (GuzzleHttp\Exception\ClientException $e) {
                $content = (string) $e->getResponse()->getBody();
                $apiResponse = json_decode($content);
                $response->code = $apiResponse->error;
                $response->msg = $apiResponse->message;
                break;
            }
            $content = (string) $apiResponse->getBody();
            $apiResponse = json_decode($content);
            $response->posts = [];
            for ($idx = 0; $idx < sizeof($apiResponse->data->children); ++$idx) {
                $post = $apiResponse->data->children[$idx];
                $last_name = $post->data->name;
                $obj = new stdClass;
                if (isset($post->data->post_hint)) {
                    switch ($post->data->post_hint) {
                        case "image":
                            if (isset($post->data->preview->images[0]->variants->mp4->source->url)) {
                                $obj->type = "video";
                            } elseif ($post->data->domain != "reddit.com" || $post->data->domain != "i.reddit.com") {
                                $obj->type = "photo_preview";
                            } else {
                                $obj->type = "photo";
                            }
                            break;
                        case "gallery":
                            $obj->type = "gallery";
                            break;
                        case "link":
                            if (isset($post->data->preview->reddit_video_preview->fallback_url) || isset($post->data->preview->images[0]->variants->mp4->source->url)) {
                                $obj->type = "video";
                            } elseif (substr_compare($post->data->url, "gifv", -strlen("gifv")) === 0) {
                                $obj->type = "imgur_media";
                            } elseif (($post->data->domain == "imgur.com" || $post->data->domain == "m.imgur.com") && isset($post->data->media->oembed->thumbnail_url)) {
                                $obj->type = "video_thumbnail";
                            } elseif (isset($post->data->preview->images[0]->source->url)) {
                                $obj->type = "photo_preview";
                            } else {
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
                } elseif (substr_compare($post->data->url, "mp4", -strlen("mp4")) === 0) {
                    $obj->type = "video_url";
                } elseif (substr_compare($post->data->url, "jpg", -strlen("jpg")) === 0) {
                    $obj->type = "photo";
                } elseif (isset($post->data->domain) && $post->data->domain == "i.redd.it") {
                    $obj->type = "photo";
                } elseif (isset($post->data->domain) && $post->data->domain == "imgur.com" && !isset($post->data->preview)) {
                    $obj->type = "imgur_media";
                } elseif (!empty($post->data->crosspost_parent_list) && !isset($post->data->rpvs_dup)) {
                    $tmp['name'] = $post->data->name;
                    $tmp['permalink'] = $post->data->permalink;
                    $tmp['subreddit'] = $post->data->subreddit;
                    $tmp['subreddit_name_prefixed'] = $post->data->subreddit_name_prefixed;
                    $tmp['crosspost_parent_list'] = $post->data->crosspost_parent_list;
                    $tmp['author'] = $post->data->author;
                    $tmp['locked'] = $post->data->locked;
                    $tmp['link_flair_text'] = $post->data->link_flair_text;
                    $tmp['link_flair_text_color'] = $post->data->link_flair_text_color;
                    $tmp['link_flair_background_color'] = $post->data->link_flair_background_color;
                    $tmp['created'] = $post->data->created;
                    $tmp['score'] = $post->data->score;
                    $tmp['num_comments'] = $post->data->num_comments;
                    $tmp['all_awardings'] = $post->data->all_awardings;
                    $tmp['total_awards_received'] = $post->data->total_awards_received;
                    $apiResponse->data->children[$idx]->data = clone $post->data->crosspost_parent_list[0];
                    $apiResponse->data->children[$idx]->data->name = $tmp['name'];
                    $apiResponse->data->children[$idx]->data->permalink = $tmp['permalink'];
                    $apiResponse->data->children[$idx]->data->subreddit = $tmp['subreddit'];
                    $apiResponse->data->children[$idx]->data->subreddit_name_prefixed = $tmp['subreddit_name_prefixed'];
                    $apiResponse->data->children[$idx]->data->crosspost_parent_list = $tmp['crosspost_parent_list'];
                    $apiResponse->data->children[$idx]->data->author = $tmp['author'];
                    $apiResponse->data->children[$idx]->data->locked = $tmp['locked'];
                    $apiResponse->data->children[$idx]->data->link_flair_text = $tmp['link_flair_text'];
                    $apiResponse->data->children[$idx]->data->link_flair_text_color = $tmp['link_flair_text_color'];
                    $apiResponse->data->children[$idx]->data->link_flair_background_color = $tmp['link_flair_background_color'];
                    $apiResponse->data->children[$idx]->data->created = $tmp['created'];
                    $apiResponse->data->children[$idx]->data->score = $tmp['score'];
                    $apiResponse->data->children[$idx]->data->num_comments = $tmp['num_comments'];
                    $apiResponse->data->children[$idx]->data->all_awardings = $tmp['all_awardings'];
                    $apiResponse->data->children[$idx]->data->total_awards_received = $tmp['total_awards_received'];
                    $apiResponse->data->children[$idx]->data->rpvs_dup = true;
                    $idx--;
                    continue;
                } else {
                    $obj->type = "unknown";
                }

                $obj->name = $post->data->name;
                $obj->title = isset($post->data->title) ? $post->data->title : "" ;
                $obj->link = $post->data->permalink;
                $obj->url = $post->data->url;
                $obj->subreddit = $post->data->subreddit;
                $obj->subreddit_url = '/'.$post->data->subreddit_name_prefixed.'/';
                $obj->author = $post->data->author;
                $obj->author_url = '/user/'.$post->data->author.'/submitted/';
                $obj->parent = !empty($post->data->crosspost_parent_list) ? $post->data->crosspost_parent_list[0]->subreddit : "" ;
                $obj->parent_url = !empty($post->data->crosspost_parent_list) ? '/'.$post->data->crosspost_parent_list[0]->subreddit_name_prefixed.'/' : "" ;
                $obj->parent_author = !empty($post->data->crosspost_parent_list) ? $post->data->crosspost_parent_list[0]->author : "" ;
                $obj->parent_author_url = !empty($post->data->crosspost_parent_list) ? '/user/'.$post->data->crosspost_parent_list[0]->author.'/submitted/' : "" ;
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
                $obj->pinned = $post->data->pinned;
                $obj->all_awardings = [];
                foreach ($post->data->all_awardings as $award) {
                    $obj->all_awardings[] = $award->resized_static_icons[0]->url;
                }
                $obj->total_awards_received = $post->data->total_awards_received;

                $obj->preview = isset($post->data->preview->images[0]->source->url) ? $post->data->preview->images[0]->source->url : "";

                $obj->external = false;

                switch ($obj->type) {
                    case "photo":
                    case "photo_preview":
                        if ($type != "all" && $type != "photo") break;
                        $obj->src = $obj->type == "photo_preview" ? $obj->preview : $obj->url;
                        $obj->external = $obj->type == "photo_preview" ? true : false;
                        $obj->type = "photo";
                        $response->posts[] = clone $obj;
                        break;
                    case "video":
                        if ($type != "all" && $type != "video") break;
                        if ($obj->domain == "redgifs.com" && false) {
                            $parts=explode('/', $obj->url);
                            if ($redgifs = file_get_contents('https://api.redgifs.com/v2/gifs/'.end($parts), false, stream_context_create(["http" => ["header" => "User-Agent: RPVS".version()."\r\n"]]))) {
                                if ($redgifs = json_decode($redgifs)) {
                                    if (isset($redgifs->gif->urls->hd)) {
                                        $obj->src = $redgifs->gif->urls->hd;
                                        $response->posts[] = clone $obj;
                                        break;
                                    } elseif (isset($redgifs->gif->urls->sd)) {
                                        $obj->src = $redgifs->gif->urls->sd;
                                        $response->posts[] = clone $obj;
                                        break;
                                    }
                                }
                            }
                        }
                        if (isset($post->data->preview->reddit_video_preview->fallback_url)) {
                            $obj->src = $post->data->preview->reddit_video_preview->fallback_url;
                        } elseif (isset($post->data->preview->images[0]->variants->mp4->source->url)) {
                            $obj->src = $post->data->preview->images[0]->variants->mp4->source->url;
                        } else {
                            $obj->src = $obj->preview;
                            $obj->external = true;
                            $obj->type = "photo";
                        }
                        $response->posts[] = clone $obj;
                        break;
                    case "video_url":
                        if ($type != "all" && $type != "video") break;
                        $obj->src = $obj->url;
                        $obj->type = "video";
                        $response->posts[] = clone $obj;
                        break;
                    case "video_thumbnail":
                        if ($type != "all" && $type != "video") break;
                        if ($url = parse_url($post->data->media->oembed->thumbnail_url)) {
                            if ($type != "all" && $type != "video") break;
                            $url = sprintf('%s://%s%s', $url['scheme'], $url['host'], str_replace("jpg", "mp4", $url['path']));
                            if (get_headers($url)[0] == "HTTP/1.1 200 OK") {
                                $obj->src = $url;
                                $obj->type = "video";
                                $response->posts[] = clone $obj;
                                break;
                            }

                        }
                        $obj->src = $obj->preview;
                        $obj->external = true;
                        $obj->type = "photo";
                        $response->posts[] = clone $obj;
                        break;
                    case "imgur_media":
                        if ($url = parse_url($obj->url)) {
                            $url = sprintf('%s://%s%s', $url['scheme'], $url['host'] == "imgur.com" ? "i.".$url['host'] : $url['host'], str_replace(".gifv", "", $url['path']));
                            if (get_headers($url.".mp4")[0] == "HTTP/1.1 200 OK") {
                                if ($type != "all" && $type != "video") break;
                                $obj->src = $url.".mp4";
                                $obj->type = "video";
                                $response->posts[] = clone $obj;
                            } elseif (get_headers($url.".jpg")[0] == "HTTP/1.1 200 OK") {
                                if ($type != "all" && $type != "photo") break;
                                $obj->src = $url.".mp4";
                                $obj->type = "photo";
                                $response->posts[] = clone $obj;
                            }
                        }
                        break;
                    case "gallery":
                        if (!isset($post->data->gallery_data->items)) break;
                        foreach ($post->data->gallery_data->items as $item) {
                            if ($post->data->media_metadata->{$item->media_id}->status == 'valid') {
                                if (isset($post->data->media_metadata->{$item->media_id}->s->mp4)) {
                                    if ($type != "all" && $type != "video") continue;
                                    $obj->src = $post->data->media_metadata->{$item->media_id}->s->mp4;
                                    $obj->type = "video";
                                } elseif (isset($post->data->media_metadata->{$item->media_id}->s->gif)) {
                                    if ($type != "all" && $type != "photo") continue;
                                    $obj->src = $post->data->media_metadata->{$item->media_id}->s->gif;
                                    $obj->type = "photo";
                                } elseif (isset($post->data->media_metadata->{$item->media_id}->s->u)) {
                                    if ($type != "all" && $type != "photo") continue;
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

            $response->after = (isset($post->data->removed_by) || isset($post->data->removed_by_category)) && $apiResponse->data->after == $last_name ? $apiResponse->data->children[$idx-2]->data->name : (isset($apiResponse->data->after) ? $apiResponse->data->after : (isset($last_name) ? $last_name : ""));
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
                $response->code = 401;
                $response->msg = $e->getMessage();
            }
        }
        else {
            $response->code = 401;
            $response->msg = "Returned state didn't match the expected value. Please go back and try again.";
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response ,JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<!-- <?php echo version();?> -->
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <meta http-equiv="Cache-Control" content="max-age=3600, must-revalidate">
        <meta name="robots" content="noindex, nofollow" />
        <meta name="description" content="">
        <meta name="author" content="">
        <meta name="theme-color" content="#222222" />
        <title>Reddit Photo Video Slider</title>
        <link rel="icon" href="/img/rpvs16.png" type="image/png">
        <script type="text/javascript" src="js/jquery-3.6.0.min.js"></script>
        <script type="text/javascript">
            const APP_NAME = 'RPVS';
            const storageKey = key => `${APP_NAME}.${key}`;
            const storageSet = (key, value) => localStorage.setItem(storageKey(key), value);
            const storageGet = key => localStorage.getItem(storageKey(key));

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
                    slideshow: false,
                    slideshowTimeout: null,
                    slideshowTimer: 5,
                    // Methods
                    save: function () {
                        storageSet('layoutType', this.layoutType);
                        storageSet('path', this.path);
                        storageSet('sort', this.sort);
                        storageSet('sortPeriod', this.sortPeriod);
                        storageSet('type', this.type);
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
                                    if (data.posts.length == 0 && data.after == "") {
                                        this.noMore = true;
                                        setMessage("No more posts.");
                                        this.display();
                                    } else {
                                        this.slides = this.slides.concat(this.filter(data.posts));
                                        this.updateLocked = false;
                                        if (this.currentSlide == 0) {
                                            this.display();
                                        }
                                        if (data.hasOwnProperty('after')) this.after = data.after;
                                    }
                                }
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
                        if ((!this.updateLocked || this.noMore) && loader) {
                            $("#loader").hide();
                        }
                        this.locked = false;
                    },
                    filter: function (data) {
                        if (filters == null || filters.length == 0) return data;
                        filters.forEach(filter => {
                            if (filter.regexp) {
                                var re = new RegExp(filter.pattern);
                            }
                            switch (filter.place) {
                                case "User":
                                    data = data.filter(el => !(filter.regexp ? re.test(el.author) : matchRuleShort(el.author, filter.pattern)));
                                    break;
                                case "Title":
                                    data = data.filter(el => !(filter.regexp ? re.test(el.title) : matchRuleShort(el.title, filter.pattern)));
                                    break;
                                case "Subred":
                                    data = data.filter(el => !(filter.regexp ? re.test(el.subreddit) : matchRuleShort(el.subreddit, filter.pattern)));
                                    break;
                                case "Flair":
                                    data = data.filter(el => !(filter.regexp ? re.test(el.link_flair_text) : matchRuleShort(el.link_flair_text, filter.pattern)));
                                    break;c
                            }
                        });
                        return data;
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
                            this.clearPostInfo();
                            $('#content').empty();
                            $('.badge').remove();
                            return;
                        }
                        if (this.slides[this.currentSlide].type == "photo") {
                            this.displayPhoto();
                        } else {
                            this.displayVideo();
                        }
                        this.clearPostInfo();
                        this.displayPostInfo();
                        if (this.currentSlide - 1 >= 0) {
                            storageSet('after', this.slides[this.currentSlide - 1].name);
                        }
                    },
                    displayHeaderInfo: function () {
                        switch (this.layoutType) {
                            case "feed":
                                $("#layout").html("Front Page");
                                break;
                            case "sr":
                            case "mr":
                                $("#layout").html(this.path.split("/").at(-2));
                                break;
                            case "u":
                                $("#layout").html(this.path.split("/").at(-3));
                                break;
                        }
                        $("#sort").html(this.sort + (this.sortPeriod != "" ? "  &#8729; " + this.sortPeriod : ""));
                    },
                    displayPostInfo: function () {
                        $("#title").html(this.slides[this.currentSlide].title).show();
                        $("#subreddit").html(this.slides[this.currentSlide].subreddit).attr('data-url', this.slides[this.currentSlide].subreddit_url).show();
                        $("#author").html(this.slides[this.currentSlide].author).attr('data-url', this.slides[this.currentSlide].author_url).show();
                        if (this.slides[this.currentSlide].parent != "") {
                            $("#author").removeClass('with-dot');
                            $("#parent").html(this.slides[this.currentSlide].parent);
                            $("#parent").attr('data-url', this.slides[this.currentSlide].parent_url);
                            if (this.slides[this.currentSlide].author != this.slides[this.currentSlide].parent_author) {
                                $("#parent-author").html(this.slides[this.currentSlide].parent_author);
                                $("#parent-author").attr('data-url', this.slides[this.currentSlide].parent_author_url);
                            } else {
                                $("#parent-author").hide();
                            }
                            $("#parent-container").show();
                        } else {
                            $("#author").addClass('with-dot');
                        }
                        $("#locked").toggle(this.slides[this.currentSlide].locked);
                        if (this.slides[this.currentSlide].link_flair_text) {
                            $("#flair").empty().append($('<span />', {
                                style: "background-color: " + (this.slides[this.currentSlide].link_flair_background_color != null && this.slides[this.currentSlide].link_flair_background_color != "" ? this.slides[this.currentSlide].link_flair_background_color : "grey") +
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
                        $("#pinned").toggle(this.slides[this.currentSlide].pinned);
                    },
                    clearPostInfo: function () {
                        $("#title").empty().hide();
                        $("#subreddit").empty().removeData().removeAttr("data-url").hide();
                        $("#parent-container div").empty().removeData().removeAttr("data-url").show(); $("#parent-container").hide();
                        $("#author").empty().removeData().removeAttr("data-url").hide();
                        $("#locked").hide();
                        $("#flair").empty().hide();
                        $("#domain").empty().hide();
                        $("#date").empty().hide();
                        $("#score").empty().hide();
                        $("#num_comments span").empty(); $("#num_comments").hide();
                        $("#nsfw").hide();
                        $("#all_awardings").empty().hide();
                        $("#total_awards_received span").empty(); $("#total_awards_received").hide();
                        $("#pinned").hide();
                    },
                    displayPhoto: function () {
                        this.iframe = new Image();
                        this.iframe.src = this.slides[this.currentSlide].src;
                        var that = this;
                        this.iframe.onerror = () => {
                            $('#content').empty().append($(".svg-container #error-icon").clone());
                            setMessage("Load error");
                            that.unlock();
                            if (that.slideshow)
                                that.slideshowTimeout = setTimeout(() => {
                                    that.show(-1);
                                }, that.slideshowTimer / 5 * 1000);
                        };
                        this.iframe.onload = function () {
                            $('#content').empty();
                            if (this.naturalHeight <= 60) {
                                this.onerror();
                                return;
                            }
                            $(this).appendTo('#content').attr('id', "photo").addClass("photo");
                            that.resize();
                            that.unlock();
                            if (that.slideshow)
                                that.slideshowTimeout = setTimeout(() => {
                                    that.show(-1);
                                }, that.slideshowTimer * 1000);
                        };
                    },
                    displayVideo: function () {
                        $('#content').empty().append($('<video />', {
                            id: 'video',
                            src: this.slides[this.currentSlide].src,
                            type: 'video/mp4',
                            poster: this.slides[this.currentSlide].preview,
                            loop: '',
                            style: 'visibility: hidden'
                        }));
                        this.iframe = $('video').first();

                        if (this.slideshow) $(this.iframe).prop('loop', false);

                        $(this.iframe).removeAttr("controls");
                        $(this.iframe).prop("controls", false);

                        $(this.iframe).prop("autoplay", true);

                        $(this.iframe).removeAttr("muted");
                        $(this.iframe).prop("muted", videoMuted);
                        $(this.iframe).prop("preload", "none");

                        var that = this;
                        var img = new Image();
                        img.src = this.slides[this.currentSlide].preview;
                        img.onload = () => {
                            that.unlock(false);
                        };

                        $(this.iframe).find('source').last().on('error', () => {
                            $("#content").empty().append($(".svg-container #error-icon").clone());
                            that.unlock();
                        });
                        $(this.iframe).on("play", function () {
                                that.resize();
                                that.unlock();
                                if (this.mozHasAudio ||
                                    Boolean(this.webkitAudioDecodedByteCount) ||
                                    Boolean(this.audioTracks && this.audioTracks.length)) {
                                    $("#content").append($(".svg-container #unmute-icon").clone().addClass("badge"));
                                } else{
                                    $("#content").append($(".svg-container #mute-icon").clone().addClass("badge"));
                                }
                            })
                            .on('loadstart', () => {
                                $("#loader").show();
                            })
                            .on('loadeddata', function () {
                                $(that.iframe).data('play-test', 'test');
                                if (that.slideshow) {
                                    if (this.duration <= 1) {
                                        var pc = Math.ceil(that.slideshowTimer / this.duration);
                                    } else if (this.duration <= 7) {
                                        var pc = 3 + that.slideshowTimer / 5 - 1;
                                    } else if (this.duration > 7 && this.duration <= 15) {
                                        var pc = 2 + that.slideshowTimer / 5 - 1;
                                    } else {
                                        var pc = 1 + that.slideshowTimer / 5 - 1;
                                    }
                                    $(that.iframe).data('play-count', pc);
                                }
                                that.iframe[0].play();
                            })
                            .on('seeking', () => {
                                $("#loader").show();
                            })
                            .on('waiting', () => {
                                if (this.duration > 1) $("#loader").show();
                            })
                            .on('canplay', () => {
                                $("#loader").hide();
                            })
                            .on('ended', () => {
                                var pc = $(that.iframe).data('play-count') - 1;
                                if (pc > 0) {
                                    $(that.iframe).data('play-count', pc);
                                    that.iframe[0].play();
                                } else {
                                    that.show(-1);
                                }
                            });
                    },
                    show: function (whereTo) {
                        if (this.slideshowTimeout) {
                            clearTimeout(this.slideshowTimeout);
                            this.slideshowTimeout = null;
                        }
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
                            if (this.currentSlide == this.slides.length - 1) setMessage("Last post");
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
                            left: (ww - newWidth) / 2,
                            visibility: 'visible'
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

                            $("#content .volume-icon").remove();
                            $("#content").append($(".svg-container #" + (this.iframe[0].muted ? "mute-icon" : "unmute-icon")).clone().addClass("volume-icon"));

                            setTimeout(() => {
                                $("#content .volume-icon").remove();
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
                        console.log("this.slideshow", this.slideshow);
                        console.log("this.slideshowTimeout", this.slideshowTimeout);
                        console.log("this.slides");
                        console.log(this.slides[this.currentSlide]);
                        console.log(this.slides);
                        console.log(layoutParams);
                    }
                };

                window.__igEmbedLoaded = function (loadedItem) {
                    console.log("??");
                };

                var mobile = [/Android/i,
                              /webOS/i,
                              /iPhone/i,
                              /iPad/i,
                              /iPod/i,
                              /BlackBerry/i,
                              /Windows Phone/i].some(agent => navigator.userAgent.match(agent))

                var messageTimer;
                function setMessage(text, className = "", object = "") {
                    if (className != "timer") {
                        $("#messages").append($("<" + (object != "" ? object : "span") + " />").addClass(className).html(text));
                        setTimeout(function () {
                            $("#messages " + (object != "" ? object : "span") + (className != "" ? "." + className : "")).first().remove();
                        }, className == "restore" ? 10000 : 5000);
                    } else {
                        if (!$('#messages span.' + className).length)
                            $("#messages").append($("<span />").addClass(className).html(text));
                        else
                            $("#messages span." + className).html(text);

                        if (messageTimer) {
                            clearTimeout(messageTimer);
                            messageTimer = 0;
                        }
                        messageTimer = setTimeout(() => {
                            $("#messages span." + className).first().remove();
                        }, 2000);
                    }
                }

                function formatTime(seconds) {
                    var hh = Math.floor(seconds / 3600);
                    var mm = Math.floor(seconds % 3600 / 60);
                    var ss = Math.round(seconds % 3600 % 60);
                    return (hh > 0 ? (hh < 10 ? "0" : "") + hh + ":" : "") + (mm < 10 ? "0" : "") + mm + ":" + (ss < 10 ? "0" : "") + ss;
                }

                function matchRuleShort(str, rule) {
                    var escapeRegex = (str) => str.replace(/([.*+?^=!:${}()|\[\]\/\\])/g, "\\$1");
                    return new RegExp("^" + rule.split("*").map(escapeRegex).join(".*") + "$").test(str);
                }

                var filters = JSON.parse(storageGet('filters'));
                $("#filter").on('click', function (e) {
                    var that = this;
                    $("#filter-modal").toggle(0, function() {
                        if($(this).is(':visible'))
                            displayFilters();
                    });
                });
                $("#addFilter").on('click', function (e) {
                    if ($("#pattern").val() == "") return;
                    if (filters == null || filters.length == 0) {
                        filters = [];
                        var filtersLastId = 0;
                    } else {
                        var filtersLastId = Math.max(...filters.map(f=>f.id));
                    }
                    filters.push({
                        id: ++filtersLastId,
                        place: $("#place").val(),
                        pattern: $("#pattern").val(),
                        regexp: $('#regexp').is(':checked')
                    });
                    $("#pattern").val("");
                    $('#regexp').prop('checked', false);
                    storageSet('filters', JSON.stringify(filters));
                    displayFilters();
                });
                function displayFilters() {
                    if (filters == null || filters.length == 0) return;
                    $("#filter-list ul").empty();
                    filters.forEach(filter => {
                        $("#filter-list ul").append(
                            $("<li />", { 'data-filter-id': filter.id })
                            .html('<strong>['+filter.place+']</strong> '
                            +(filter.regexp ? "<tt>/" : "")+filter.pattern+(filter.regexp ? "/</tt>" : "")
                            +' <a href="#" class="del-filter-btn" data-filter-id="'+filter.id+'" style="color: white">del</a>'));
                    })
                    $('.del-filter-btn').on('click', function (e) {
                        filters = filters.filter(filter => filter.id !== $(this).data('filter-id'));
                        storageSet('filters', JSON.stringify(filters));
                        $('#filter-list ul li[data-filter-id="'+$(this).data('filter-id')+'"]').remove();
                    });
                }
                $("#importFilters").on('click', function (e) {
                    if (!$("#filtersJSON").is(':visible')) {
                        $("#filtersJSON").show();
                        return;
                    }
                    if ($("#filtersJSON").val() == "") return;
                    const json = (function(raw) {
                        try {
                            return JSON.parse(raw);
                        } catch (err) {
                            return false;
                        }
                    })($("#filtersJSON").val());
                    if (json && Array.isArray(json)) {
                        let i=0,integrity=true;
                        json.forEach(el => {
                            if (el.hasOwnProperty("place") && el.hasOwnProperty("pattern") && el.hasOwnProperty("regexp"))
                                el.id=++i;
                            else {
                                integrity = false;
                                return;
                            }
                        });
                        if (integrity) {
                            $("#filtersJSON").val('').hide();
                            filters = json;
                            storageSet('filters', JSON.stringify(filters));
                            displayFilters();
                        }
                    } else {
                        console.log("JSON syntax error")
                    }
                });
                $("#exportFilters").on('click', function (e) {
                    $("#filtersJSON").val(JSON.stringify(filters
                                .map(({id, ...keepAttrs}) => keepAttrs)
                                .sort((a, b) => ['Title','Flair','Subred','User'].indexOf(a.place) - ['Title','Flair','Subred','User'].indexOf(b.place))
                            ))
                        .show();
                });

                layouts.push({
                    __proto__: layout$
                });

                currentLayout = layouts[0];

                currentLayout.save();
                currentLayout.displayHeaderInfo();
                currentLayout.update();

                layoutParams = {
                    layoutType: storageGet('layoutType'),
                    path: storageGet('path'),
                    sort: storageGet('sort'),
                    sortPeriod: storageGet('sortPeriod'),
                    type: storageGet('type'),
                    after: storageGet('after')
                }

                $(document).on("click", ".restore", function () {
                    $(this).remove();
                    $('#content').empty();
                    currentLayout = {
                        __proto__: layout$,
                        layoutType: layoutParams.layoutType,
                        path: layoutParams.path,
                        sort: layoutParams.sort,
                        sortPeriod: layoutParams.sortPeriod,
                        type: layoutParams.type,
                        after: layoutParams.after
                    }
                    if (layoutParams.layoutType == "feed") {
                        layouts.splice(0, 0, currentLayout)
                    } else {
                        layouts.push(currentLayout);
                    }
                    currentLayout.clearPostInfo();
                    currentLayout.update(true);
                    currentLayout.displayHeaderInfo();
                    currentLayout.save();
                    $("#type-" + currentLayout.type).prop('checked', true);
                    $("#back").toggle(currentLayout.layoutType != "feed");
                });

                $(document).one('auth', function (e) {
                    if (
                        typeof layoutParams.after !== 'undefined' &&
                        layoutParams.after !== null &&
                        storageGet('lastVisit') !== null &&
                        Math.floor(Date.now() / 1000) - storageGet('lastVisit') <= 7200
                    )
                        setMessage("Restore", "restore", "button");
                    storageSet('lastVisit', Math.floor(Date.now() / 1000))
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

                $(window).bind('beforeunload', function(e){
                    e.preventDefault();
                    return e.returnValue = "Are you sure you want to leave?";
                });

                history.pushState({}, "");
                $(window).bind('popstate', function(e) {
                    if (layouts.length > 1) {
                        $("#back").click();
                    } else {
                        history.go(2-history.length);
                    }
                    history.pushState({}, "");
                });

                $(window).resize(function (e) {
                    currentLayout.resize()
                });

                $("#content").on('click', function (e) {
                    if ($("#help").is(":visible")) {
                        $("#help").hide();
                        return;
                    }
                    if ($("#sort-menu").is(":visible")) {
                        $("#sort-menu").hide();
                        return;
                    }
                    if ($("#sidebar").is(":visible")) {
                        $("#sidebar").hide();
                        return;
                    }
                    if ($("#filter-modal").is(":visible")) {
                        $("#filtersJSON").val("").hide();
                        $("#filter-modal").hide();
                        return;
                    }
                    $(".header").toggle();
                });
                $(document).on('click', '.subreddit, .multireddit, #author, #parent-author', function (e) {
                    layouts.push({
                        __proto__: layout$,
                        layoutType: this.id == "author" || this.id == "parent-author" ? "u" : $(this).hasClass("multireddit") ? "mr" : "sr",
                        path: $(this).data("url")
                    });
                    currentLayout = layouts[layouts.length - 1];
                    currentLayout.save();
                    currentLayout.displayHeaderInfo();
                    currentLayout.clearPostInfo();
                    currentLayout.update();
                    $("#type-" + currentLayout.type).prop('checked', true);
                    $("#sidebar").hide();
                    $("#sort-menu").hide();
                    $("#help").hide();
                    $("#back").show();
                    if (layouts.length > 2) $("#home").show();
                    toggleSlideshow();
                });
                $("#open-post").on('click',function (e){
                    if (typeof currentLayout.slides[currentLayout.currentSlide].link !== 'undefined' && currentLayout.slides[currentLayout.currentSlide].link !== '')
                        window.open('https://reddit.com' + currentLayout.slides[currentLayout.currentSlide].link);
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
                    $("#type-" + currentLayout.type).prop('checked', true);
                    currentLayout.save();
                    currentLayout.displayHeaderInfo();
                    currentLayout.display();
                    $("#sidebar").hide();
                    $("#sort-menu").hide();
                    $("#help").hide();
                    if (layouts.length < 3) $("#home").hide();
                    if (layouts.length == 1) $("#back").hide();
                    toggleSlideshow();
                });
                $("#layout").on('click', function (e) {
                    $("#sidebar").toggle();
                    if (!mobile)
                        $("#search").focus()
                    $("#sort-menu").hide();
                    $("#help").hide();
                });
                $("#sort").on('click', function (e) {
                    $("#sort-menu").toggle();
                    $("#sort-best").toggle(currentLayout.layoutType == "feed");
                    $("#sort-order").show();
                    $("#sort-period").hide();
                });
                $("#sort-order li").on('click', function (e) {
                    if (["sort-best", "sort-hot", "sort-new","sort-rising"].includes(this.id)) {
                        currentLayout.sort = this.id.split("-")[1];
                        currentLayout.sortPeriod = "";
                        currentLayout.after = "";
                        currentLayout.currentSlide = 0;
                        currentLayout.updateLocked = false;
                        currentLayout.slides = [];
                        $("#help").hide();
                        $("#sort-menu").hide();
                        $("#sidebar").hide();
                        currentLayout.save();
                        currentLayout.displayHeaderInfo();
                        currentLayout.clearPostInfo();
                        currentLayout.update();
                    }
                    if (["sort-controversial", "sort-top"].includes(this.id)) {
                        $("#sort-order").hide();
                        $("#sort-period li").data("order", this.id.split("-")[1]);
                        $("#sort-period").show();
                    }
                });
                $("#sort-period li").on('click', function (e) {
                    currentLayout.sort = $(this).data("order");
                    currentLayout.sortPeriod = this.id.split("-")[1];
                    currentLayout.after = "";
                    currentLayout.currentSlide = 0;
                    currentLayout.updateLocked = false;
                    currentLayout.slides = [];
                    $("#help").hide();
                    $("#sort-menu").hide();
                    $("#sidebar").hide();
                    currentLayout.save();
                    currentLayout.displayHeaderInfo();
                    currentLayout.clearPostInfo();
                    currentLayout.update();
                });
                $("#close").on('click', function (e) {
                    $("#sidebar").hide();
                });
                $("#help").on('click', function (e) {
                    $("#help").hide();
                });
                $("#fullscreen").on('click', function (e) {
                    var requestFullScreen = document.documentElement.requestFullscreen || document.documentElement.mozRequestFullScreen || document.documentElement.webkitRequestFullScreen || document.documentElement.msRequestFullscreen;
                    var cancelFullScreen = document.exitFullscreen || document.mozCancelFullScreen || document.webkitExitFullscreen || document.msExitFullscreen;
                    if (!document.fullscreenElement && !document.mozFullScreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement)
                        requestFullScreen.call(document.documentElement);
                    else
                        cancelFullScreen.call(document);
                });
                $('input[name="type"]').change(function () {
                    $('#content').empty();
                    currentLayout.type=this.id.split("-")[1];
                    currentLayout.after="";
                    currentLayout.currentSlide=0;
                    currentLayout.slides=[];
                    currentLayout.save();
                    currentLayout.clearPostInfo();
                    currentLayout.update();
                    $("#sibebar, #sort-menu").hide();
                });

                $("#slideshow").on('click', function (e) {
                    currentLayout.slideshow = !currentLayout.slideshow;
                    toggleSlideshow(true);
                });

                function toggleSlideshow(clickBtn = false) {
                    if (currentLayout.slideshow) {
                        //start slideshow
                        $('#play').removeClass('visible').addClass('hidden');
                        $('#pause').removeClass('hidden').addClass('visible');
                        $('#slideshow-timer').show();
                        switch (currentLayout.slides[currentLayout.currentSlide].type) {
                        case 'photo':
                            currentLayout.slideshowTimeout = setTimeout(() => {
                                currentLayout.show(-1);
                            }, currentLayout.slideshowTimer * 1000);
                            break;
                        case 'video':
                            $(currentLayout.iframe[0]).prop('loop', false)
                            break;
                        }
                    } else {
                        //stop slideshow
                        $('#play').removeClass('hidden').addClass('visible');
                        $('#pause').removeClass('visible').addClass('hidden');
                        $('#slideshow-timer').hide();
                        if (currentLayout.slideshowTimeout) {
                            clearTimeout(currentLayout.slideshowTimeout);
                            currentLayout.slideshowTimeout = null;
                        }
                        if (clickBtn && currentLayout.slides[currentLayout.currentSlide].type == 'video') $(currentLayout.iframe[0]).prop('loop',true);
                    }
                }

                $("#slideshow-timer").on('click', function (e) {
                    currentLayout.slideshowTimer = (currentLayout.slideshowTimer / 5 % 3 + 1) * 5;
                    switch (currentLayout.slideshowTimer) {
                        case 5:
                            $('#timer-five').toggleClass('hidden');
                            $('#timer-fifteen').toggleClass('hidden');
                            break;
                        case 10:
                            $('#timer-five').toggleClass('hidden');
                            $('#timer-ten').toggleClass('hidden');
                            break;
                        case 15:
                            $('#timer-ten').toggleClass('hidden');
                            $('#timer-fifteen').toggleClass('hidden');
                            break;
                    }
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
                var stealthMode = !mobile;
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
                        $(li).toggle($(li).text().toLowerCase().indexOf($(this).val().toLowerCase()) !== -1);
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
                        case 83: // 's'
                            // subreddit
                            $("#subreddit").click();
                            break;
                        case 88: // 'x'
                            // author
                            $("#author").click();
                            break;
                        case 69: // 'e'
                            // parent
                            $("#parent").click();
                            break;
                        case 82: // 'r'
                            // parent-author
                            $("#parent-author").click();
                            break;
                        /*case 70: // 'f'
                            // follow
                            $("#follow").click();
                            break;*/
                        case 78: // 'n'
                            // photo
                            $("#type-photo").trigger('click');
                            break;
                        case 66: // 'b'
                            // all
                            $("#type-all").trigger('click');
                        case 86: // 'v'
                            // video
                            $("#type-video").trigger('click');
                            break;
                        case 79: // 'o'
                            // open post
                            $("#open-post").click();
                            break;
                        /*case 76: // 'l'
                            // like post
                            $("#like-post").click();
                            break;*/
                        case 32: // space
                            $("#content").click();
                            break;
                        case 67: // 'c'
                            //ff
                            currentLayout.seek(1);
                            break;
                        case 90: // 'z'
                            //rew
                            currentLayout.seek(-1);
                            break;
                        case 220: // '\'
                            stealthMode = !stealthMode;
                            videoMuted = stealthMode;
                            setMessage("Stealth mode " + (stealthMode ? "enabled" : "disabled"));
                            break;
                        case 192: // '`'
                            // boss key
                            if (stealthMode) {
                                $('body').toggle();
                                keyHidden = $('body').is(":hidden");
                                if (currentLayout.wasHidden) {
                                    currentLayout.resize();
                                }
                            }
                            break;
                        case 77: // 'm'
                            // mute
                            currentLayout.muteToggle();
                            break;
                        case 72: // 'h'
                            // help
                            $('#help').toggle();
                            break;
                        case 73: // 'i'
                            // test
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
                var scaling = false;
                var distDown, distUp = null;
                $("#content").bind('touchstart', function (ev) {
                    ev.stopPropagation();
                    if (touchOff) { return; }
                    var e = ev.originalEvent;
                    if (e.touches.length === 2) {
                        scaling = true;
                        distDown = Math.hypot(
                            e.touches[0].clientX - e.touches[1].clientX,
                            e.touches[0].clientY - e.touches[1].clientY);
                        return;
                    }
                    xDown = e.touches[0].clientX;
                    yDown = e.touches[0].clientY;
                });
                $("#content").bind('touchmove', function (ev) {
                    ev.stopPropagation();
                    if (touchOff) { return; }
                    var e = ev.originalEvent;
                    if (scaling) {
                        distUp = Math.hypot(
                            e.touches[0].clientX - e.touches[1].clientX,
                            e.touches[0].clientY - e.touches[1].clientY);
                        return;
                    }
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
                $("#content").bind('touchend', function (ev) {
                    ev.stopPropagation();
                    if (touchOff) { return; }
                    if (scaling) {
                        scaling = false;
                        var notFullScreen = !document.fullscreenElement && !document.mozFullScreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement;
                        var requestFullScreen = document.documentElement.requestFullscreen || document.documentElement.mozRequestFullScreen || document.documentElement.webkitRequestFullScreen || document.documentElement.msRequestFullscreen;
                        var cancelFullScreen = document.exitFullscreen || document.mozCancelFullScreen || document.webkitExitFullscreen || document.msExitFullscreen;
                        if (distDown < distUp) {
                            setMessage("Zoom in");
                            if (notFullscreen)
                                requestFullScreen.call(document.documentElement);
                        } else {
                            setMessage("Zoom out");
                            if (!notFullscrenn)
                                cancelFullScreen.call(document);
                        }
                        return;
                    }
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
                $("#content").mousedown(function (ev) {
                    var e = ev.originalEvent;
                    if (e.which == 2) {
                        mouseButtonDown = true;
                        xDown = e.clientX;
                        yDown = e.clientY;
                    }
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
            }

            html,
            body {
                height: 100%;
                color: white;
                background: black;
                touch-action: none;
                font-family: sans-serif;
            }

            .subheader-container {
                cursor: default;
            }

            .button,
            .subreddit,
            .multireddit,
            #author,
            #parent-author,
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

            .restore {
                margin: 5px 0;
                padding: 1px 6px;
                color: darkgray;
                background-color: transparent;
                border-width: 3px;
                border-color: darkgray;
                border-radius: 5px;
                font-size: 1.5rem;
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

            #parent-container {
                margin-left: 5px;
            }

            #parent-icon {
                width: 1rem;
                height: 1rem;
                fill: green;
            }

            #flair span {
                padding: 2px;
                text-shadow: none;
                border-radius: 5px;
            }

            #score {
                font-weight: bold;
                font-size: large;
            }

            #nsfw, #pinned {
                text-shadow: none;
                vertical-align: bottom;
                margin: 0 8px;
            }

            #pinned {
                font-size: 0.7rem;
                margin-left: 0;
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

            .radio-toolbar {
                display: inline-block;
                height: 2rem;
            }
            .radio-toolbar input[type="radio"] {
                display: none;
            }

            .radio-toolbar label {
                display: inline-block;
                vertical-align: middle;
                cursor: pointer;
            }

            .radio-toolbar input[type="radio"]:checked+label svg {
                fill: dodgerblue;
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

            #sort-menu, #help, #filter-modal {
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
                content: '\25B6';
                margin-left: 0.5rem;
                font-size: small;
                vertical-align: text-top;
            }

            #sort-menu .svg-icon {
                margin-right: 1rem;
            }

            #help {
                display: none;
                width: 25rem;
            }

            #help dl {
                padding-top: 1rem;
            }

            #help dl dt {
                float: left;
                width: 8rem;
            }

            #help dl dd {
                margin-bottom: 1rem;
            }

            #help dl dt span {
                border: 1px solid white;
                border-radius: 2px;
                padding: 3px;
                font-family: monospace;
                font-size: 0.9rem;
                background-color: darkgray;
                color: black;
                margin-left: 5px;
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

            #mute-icon.badge,
            #unmute-icon.badge {
                top: initial;
                left: initial;
                right: 1rem;
                bottom: 1rem;
                transform: none;
                width: 3rem;
                height: 3rem;
            }

            #filter-modal {
                display: none;
                width: 22rem;
            }

            #filter-form, #filter-list {
                margin-bottom: 1rem;
            }

            #filter-list {
                max-height: 25rem;
                overflow-y: auto;
            }

            #pattern {
                width: 9rem;
            }
            #filter-list ul {
                list-style: none;
            }

            #filtersJSON {
                display: none;
                width: 100%;
                height: 5rem;
            }

            .visible {
                visibility: visible;
            }

            .hidden {
                visibility: hidden;
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

                <svg id="slideshow" class="svg-icon button" viewBox="0 0 100 100" x="0px" y="0px" width="100" height="100">
                    <g id="play">
                        <polygon points="7.434,100.00031870000001 7.434,-1.3e-06 92.5662,50.0001587"/>
                    </g>
                    <g id="pause" class="hidden">
                        <path d="M 7.4338083,-5.4499998e-7 H 35.947047 V 99.999999 H 7.4338083 Z" />
                        <path d="M 64.052956,-5.4499998e-7 H 92.566195 V 99.999999 H 64.052956 Z" />
                    </g>
                </svg>

                <svg id="slideshow-timer" class="svg-icon button" style="display:none" x="0px" y="0px" viewBox="0 0 100 100" width="100" height="100">
                    <path d="M 81.258681,67.555554 96.296875,45.111109 H 86.036458 C 83.592014,24.666666 66.147569,8.666666 45.036458,8.666666 22.258681,8.666666 3.703125,27.222221 3.703125,50 c 0,22.777777 18.555556,41.333334 41.333333,41.333334 8.333334,0 16.333334,-2.444446 23.111111,-7.111111 l -5.777777,-8.444445 c -5.111111,3.444445 -11.111111,5.333332 -17.333334,5.333332 -17.111111,0 -31.111111,-14 -31.111111,-31.11111 0,-17.111112 14,-31.111112 31.111111,-31.111112 15.444445,0 28.333334,11.333334 30.666667,26.222223 h -9.326389 z"/>
                    <g id="timer-five">
                        <path d="m 40.213543,45.625 q 2.5,-0.5 4.6875,-0.5 2.21875,0 4.375,0.875 2.1875,0.84375 3.625,2.3125 2.875,2.9375 2.875,7.59375 0,5.8125 -3.78125,9.0625 -3.78125,3.25 -10.78125,3.25 -3,0 -7.25,-1.1875 v -6 q 4.125,1.1875 7.3125,1.1875 8.71875,0 8.71875,-6.125 0,-5.53125 -7.53125,-5.53125 -3.25,0 -6,1.15625 l -2.09375,-3.15625 2.25,-16.78125 h 17.0625 v 5.65625 h -12.28125 z"/>
                    </g>
                    <g id="timer-ten" class="hidden">
                        <path d="m 36.588543,67.90625 h -5.25 V 37.9375 l -6.90625,2.71875 V 35 l 6.90625,-2.9375 h 5.25 z"/>
                        <path d="m 65.307293,49.90625 q 0,8.375 -3.3125,13.46875 -3.28125,5.09375 -9.65625,5.09375 -6.375,0 -9.59375,-5.09375 -3.21875,-5.125 -3.21875,-13.46875 0,-12.9375 7.1875,-17 2.4375,-1.375 7.3125,-1.375 4.90625,0 8.09375,5 3.1875,5 3.1875,13.375 z m -7.875,9.9375 q 1.78125,-3.3125 1.78125,-9.9375 0,-6.625 -1.78125,-9.75 Q 55.651043,37 52.401043,37 q -3.25,0 -5.15625,3.15625 -1.875,3.125 -1.875,9.71875 0,6.59375 1.90625,9.9375 1.9375,3.3125 5.15625,3.3125 3.25,0 5,-3.28125 z"/>
                    </g>
                    <g id="timer-fifteen" class="hidden">
                        <path d="m 38.557293,67.65625 h -5.25 V 37.6875 l -6.90625,2.71875 V 34.75 l 6.90625,-2.9375 h 5.25 z"/>
                        <path d="m 47.776043,45.625 q 2.5,-0.5 4.6875,-0.5 2.21875,0 4.375,0.875 2.1875,0.84375 3.625,2.3125 2.875,2.9375 2.875,7.59375 0,5.8125 -3.78125,9.0625 -3.78125,3.25 -10.78125,3.25 -3,0 -7.25,-1.1875 v -6 q 4.125,1.1875 7.3125,1.1875 8.71875,0 8.71875,-6.125 0,-5.53125 -7.53125,-5.53125 -3.25,0 -6,1.15625 l -2.09375,-3.15625 2.25,-16.78125 h 17.0625 v 5.65625 h -12.28125 z"/>
                    </g>
                </svg>


                <div class="radio-toolbar button">
                    <input type="radio" id="type-photo" name="type">
                    <label class="button" for="type-photo">
                    <svg class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100" height="100">
                        <path d="M 6e-7,2e-6 V 99.999998 H 99.857089 L 99.999999,2e-6 Z m 8.6084168,8.47 H 91.534499 v 64.88784 L 73.497012,58.118139 57.389452,71.734008 26.376191,41.453461 8.6084174,52.980163 Z M 68.724428,22.942097 c -4.279305,-3.09e-4 -7.748444,3.468831 -7.748135,7.748136 -3.1e-4,4.279305 3.46883,7.748446 7.748135,7.748137 4.279306,3.1e-4 7.748447,-3.468831 7.748137,-7.748137 3.09e-4,-4.279306 -3.468831,-7.748446 -7.748137,-7.748136 z"/>
                    </svg>
                    </label>

                    <input type="radio" id="type-all" name="type" checked>
                    <label class="button" for="type-all">
                        <svg class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100" height="100">
                            <path d="M 0 0 L 0 100 L 50 100 L 99.857422 100 L 100 0 L 50 0 L 0 0 z M 8.609375 8.4707031 L 50 8.4707031 L 91.535156 8.4707031 L 91.535156 73.357422 L 73.496094 58.117188 L 57.388672 71.734375 L 50 64.519531 L 50 91.529297 L 8.609375 91.529297 L 8.609375 8.4707031 z M 68.724609 22.941406 C 64.445299 22.941106 60.976253 26.410153 60.976562 30.689453 C 60.976253 34.968763 64.445299 38.43781 68.724609 38.4375 C 73.003909 38.43781 76.472976 34.968763 76.472656 30.689453 C 76.472966 26.410143 73.003909 22.941106 68.724609 22.941406 z M 25 25 L 25 75 L 50 62.5 L 50 37.5 L 25 25 z " />
                        </svg>
                    </label>

                    <input type="radio" id="type-video" name="type">
                    <label class="button" for="type-video">
                    <svg class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100" height="100">
                        <path d="M 0,-1.5e-6 V 100 H 99.85742 L 100,-1.5e-6 Z m 8.60938,8.470703 H 91.53516 V 91.529291 H 8.60938 Z M 25,24.999998 V 75.000001 L 75,49.999999 Z"/>
                    </svg>
                    </label>
                </div>

                <svg id="filter" class="svg-icon button" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                  <polygon points="61.5417,42.3082 100.0032,3.8466 0.0032,3.8466 38.4648,42.3081 38.4648,96.1543 61.5417,80.7697"/>
                </svg>

                <svg id="open-post" class="svg-icon button" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                    <path d="M 69.230769,8.9743398 V 20.352545 C 52.362308,24.638186 28.322436,36.108955 20.512821,60.256429 40.027692,43.130622 67.62,44.996455 69.230769,45.072096 v 11.37818 L 100,32.732353 Z M 5.1282051,16.666647 C 2.4432051,16.666917 2.5641026e-4,19.109801 0,21.794853 v 64.102602 c 2.5641026e-4,2.685 2.4432051,5.127949 5.1282051,5.128205 H 71.794872 c 2.685,-2.56e-4 5.127949,-2.443205 5.128205,-5.128205 V 56.971173 l -4.567308,3.525641 -5.689102,4.407051 V 80.76925 H 10.25641 V 26.923058 h 30.889487 c 7.50218,-4.802372 15.465129,-8.103911 22.596154,-10.256411 z"/>
                </svg>

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
                <div id="author" class="subheader-container with-dot" style="display: none;"></div>
                <div id="parent-container" class="subheader-container" style="display: none;">
                    <svg id="parent-icon" class="svg-icon" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                        <path d="m 71.514718,69.553009 c -6.753854,-0.893227 -10.975013,-5.016394 -16.42483,-10.877021 -2.626917,-2.834206 -5.381977,-5.962386 -8.758904,-8.740059 5.261373,-4.390759 9.090567,-9.497607 13.127049,-13.202427 3.81035,-3.471149 7.164663,-5.728715 12.056685,-6.361889 V 40.07274 L 99.992462,23.629066 71.514718,7.185392 v 9.9951 c -12.591867,1.002525 -20.54046,8.962424 -26.137264,15.124562 -3.082953,3.376927 -5.792786,6.376965 -8.336788,8.26141 -2.58169,1.888215 -4.62443,2.751291 -7.586778,2.796518 -0.0038,0 -0.0075,0 -0.01131,0 H 0 v 13.19112 h 0.0038 v 0.0038 c 0,0 0.173369,-0.0038 0.516338,-0.0038 h 28.933781 c 2.962348,0.04146 5.012625,0.908303 7.601854,2.800287 3.848038,2.796517 7.895828,8.219952 13.387103,13.447405 4.944785,4.741266 11.921004,9.267704 21.07941,9.972487 V 92.814608 L 100,76.397317 71.522255,59.96118 l -0.0075,9.591829 z"/>
                    </svg>
                    <div id="parent" class="subheader-container with-dot subreddit"></div>
                    <div id="parent-author" class="subheader-container with-dot"></div>
                </div>
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
                <div id="pinned" class="subheader-container" style="display: none;">&#128204;</div>
            </div>
        </div>
        <div id="messages"></div>
        <div id="content"></div>
        <div id="sidebar">
            <svg id="close" class="svg-icon button" x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                <path d="M88.8,77.5L60.6,49.3l28.2-28.2c1.2-1.2,1.2-3.1,0-4.2l-8.5-8.5L50,38.7L19.6,8.3l-8.5,8.5c-1.2,1.2-1.2,3.1,0,4.2  l28.2,28.2L11.2,77.5c-1.2,1.2-1.2,3.1,0,4.2l8.5,8.5L50,59.9l30.4,30.4l8.5-8.5C90,80.6,90,78.7,88.8,77.5z"/>
            </svg>
            <div class="sidebar-container">
                <input id="search" placeholder="Search..." type="text" autocomplete="off" />
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
        <div id="help" style="">
            <dl>
              <dt><span>a</span><span>left</span></dt><dd>Go to prev slide</dd>
              <dt><span>d</span><span>right</span></dt><dd>Go to next slide</dd>
              <dt><span>q</span></dt><dd>Go to front page</dd>
              <dt><span>w</span></dt><dd>Go back</dd>
              <dt><span>e</span></dt><dd>Go to subreddit</dd>
              <dt><span>s</span></dt><dd>Go to parent subreddit</dd>
              <dt><span>x</span></dt><dd>Go to author</dd>
              <dt><span>n</span></dt><dd>Switch type to photo</dd>
              <dt><span>b</span></dt><dd>Switch type to photo and video</dd>
              <dt><span>v</span></dt><dd>Switch type to video</dd>
              <dt><span>o</span></dt><dd>Open post</dd>
              <dt><span>space</span></dt><dd>Hide header</dd>
              <dt><span>c</span></dt><dd>Fast forward for video</dd>
              <dt><span>z</span></dt><dd>Rewind for video</dd>
              <dt><span>\</span></dt><dd>Toggle stealth mode</dd>
              <dt><span>`</span></dt><dd>Boss key</dd>
              <dt><span>m</span></dt><dd>Mute video</dd>
              <dt><span>h</span></dt><dd>Toggle this help</dd>
              <dt><span>i</span></dt><dd>Test</dd>
            </dl>
        </div>
        <div id="filter-modal">
            <div id="filter-list">
                <ul></ul>
            </div>
            <div id="filter-form">
                <select id="place">
                    <option value="Title">Title</option>
                    <option value="Flair">Flair</option>
                    <option value="Subred">Subreddit</option>
                    <option value="User">User</option>
                </select>
                <input id="pattern" type="text">
                <input id="regexp" type="checkbox"><label for="regexp">RegExp</label>
                <button id="addFilter" type="button">Add</button>
            </div>
            <div id="filter-import-export">
                <textarea id="filtersJSON" type="textarea"></textarea>
                <button id="importFilters" type="button">Import</button>
                <button id="exportFilters" type="button">Export</button>
            </div>
        </div>
        <div class="svg-container" style="display:none">
            <svg id="error-icon">
                <svg viewBox="0 0 253 253" x="0px" y="0px" width="100%" height="100%">
                    <polygon points="86,127 0,41 41,0 127,86 213,0 253,41 167,127 253,213 213,253 127,167 41,253 0,213 "/>
                </svg>
            </svg>
            <svg id="mute-icon">
                <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                    <path d="M70.564,57.797c0.538-0.797,0.988-1.616,1.339-2.445  c0.374-0.885,0.663-1.809,0.855-2.76c0.18-0.892,0.275-1.861,0.275-2.902c0-1.04-0.096-2.009-0.275-2.901  c-0.192-0.951-0.481-1.875-0.855-2.76c-0.351-0.829-0.801-1.648-1.339-2.445c-0.517-0.769-1.116-1.493-1.785-2.162  c-1.332-1.332-2.895-2.4-4.608-3.125c-1.758-0.741-2.582-2.766-1.842-4.523c0.74-1.758,2.766-2.583,4.524-1.843  c2.59,1.096,4.905,2.663,6.829,4.587c0.966,0.965,1.847,2.035,2.626,3.191c0.748,1.109,1.407,2.327,1.961,3.639  c0.549,1.296,0.975,2.663,1.262,4.087c0.286,1.421,0.437,2.846,0.437,4.256c0,1.411-0.15,2.836-0.437,4.258  c-0.287,1.423-0.713,2.79-1.262,4.086c-0.554,1.312-1.213,2.529-1.961,3.638c-0.219,0.325-0.445,0.644-0.68,0.953  c-0.809,1.071-0.966,0.67-1.884-0.249c-1.096-1.096-1.619-1.619-2.714-2.714C70.203,58.833,69.931,58.74,70.564,57.797z"/>
                    <path d="M80.397,67.533c0.736-0.845,1.417-1.73,2.04-2.653  c0.989-1.47,1.829-3.006,2.5-4.591c0.7-1.653,1.239-3.384,1.601-5.173c0.346-1.709,0.526-3.522,0.526-5.427  s-0.181-3.717-0.526-5.425c-0.361-1.789-0.9-3.52-1.601-5.173c-0.671-1.585-1.511-3.122-2.5-4.591  c-0.977-1.446-2.098-2.803-3.344-4.049c-1.246-1.247-2.603-2.368-4.049-3.343c-1.469-0.99-3.007-1.83-4.592-2.501  c-1.758-0.74-2.582-2.766-1.842-4.524s2.767-2.583,4.523-1.842c2.067,0.875,4.003,1.924,5.784,3.125  c1.836,1.238,3.536,2.64,5.079,4.182c1.542,1.543,2.944,3.244,4.182,5.08c1.202,1.78,2.25,3.716,3.124,5.783  c0.874,2.065,1.551,4.24,2.008,6.501C93.763,45.146,94,47.415,94,49.689c0,2.275-0.237,4.545-0.688,6.781  c-0.457,2.261-1.134,4.436-2.008,6.501c-0.874,2.066-1.923,4.001-3.124,5.782c-0.875,1.298-1.832,2.527-2.861,3.682  c-0.813,0.911-0.86,0.651-1.708-0.195c-1.101-1.102-1.9-1.9-3.001-3.001C79.741,68.371,79.604,68.443,80.397,67.533z"/>
                    <path d="M20.096,32.725h-8.13C8.685,32.725,6,35.41,6,38.691v22.484  c0,3.28,2.685,5.966,5.966,5.966h13.441c6.906,5.195,13.81,10.393,20.713,15.591c3.148,2.368,6.793,1.988,6.793-2.12  c0-4.925,0-9.442,0-14.365c0-1.745-0.261-2.222-1.506-3.468c-9.727-9.727-19.453-19.454-29.18-29.181  C21.37,32.739,21.311,32.725,20.096,32.725z"/>
                    <path d="M12.589,12.588L12.589,12.588c2.116-2.115,5.577-2.115,7.692,0  l67.13,67.131c2.116,2.115,2.116,5.576,0,7.691l0,0c-2.115,2.116-5.576,2.116-7.691,0l-67.131-67.13  C10.474,18.165,10.474,14.704,12.589,12.588z"/>
                    <path d="M46.121,17.134c-2.872,2.163-5.744,4.325-8.617,6.487  c-1.585,1.193-0.805,1.707,0.599,3.11c4.504,4.506,9.009,9.011,13.514,13.516c1.004,1.016,1.297,0.896,1.297-0.191v-20.8  C52.914,15.147,49.269,14.766,46.121,17.134z"/>
                </svg>
            </svg>
            <svg id="unmute-icon">
                <svg x="0px" y="0px" viewBox="0 0 100 100" width="100%" height="100%">
                    <path d="M66.853,69.518c-1.759,0.74-3.783-0.085-4.524-1.843c-0.74-1.759,0.086-3.783,1.844-4.522  c0.828-0.353,1.647-0.803,2.445-1.34c0.769-0.518,1.491-1.117,2.162-1.785c0.668-0.67,1.27-1.395,1.785-2.162  c0.538-0.797,0.988-1.617,1.339-2.446c0.374-0.886,0.663-1.808,0.854-2.761c0.181-0.893,0.275-1.86,0.275-2.9  s-0.096-2.01-0.275-2.901c-0.191-0.952-0.48-1.875-0.854-2.76c-0.351-0.829-0.801-1.649-1.339-2.445  c-0.518-0.768-1.117-1.492-1.785-2.162c-1.332-1.331-2.895-2.399-4.607-3.125c-1.758-0.74-2.584-2.766-1.844-4.523  c0.741-1.759,2.767-2.583,4.524-1.843c2.59,1.097,4.905,2.664,6.83,4.588c0.966,0.966,1.846,2.034,2.625,3.19  c0.748,1.109,1.407,2.326,1.962,3.638c0.548,1.297,0.975,2.664,1.262,4.088c0.286,1.42,0.438,2.845,0.438,4.256  s-0.15,2.836-0.438,4.256c-0.287,1.426-0.714,2.791-1.262,4.087c-0.555,1.312-1.214,2.53-1.962,3.64  c-0.779,1.156-1.659,2.226-2.625,3.19c-0.966,0.966-2.036,1.847-3.19,2.627C69.383,68.303,68.164,68.962,66.853,69.518z"/>
                    <path d="M73.136,81.207c-1.759,0.74-3.783-0.084-4.524-1.842c-0.74-1.758,0.085-3.783,1.844-4.524  c1.584-0.67,3.121-1.511,4.59-2.501c1.447-0.975,2.805-2.098,4.051-3.344s2.367-2.603,3.343-4.049c0.99-1.469,1.83-3.006,2.501-4.59  c0.699-1.654,1.239-3.386,1.602-5.175c0.345-1.708,0.525-3.521,0.525-5.426s-0.182-3.719-0.525-5.427  c-0.361-1.787-0.901-3.52-1.602-5.173c-0.671-1.584-1.511-3.122-2.501-4.591c-0.976-1.446-2.097-2.803-3.343-4.049  s-2.604-2.367-4.051-3.343c-1.469-0.99-3.006-1.83-4.59-2.501c-1.759-0.741-2.584-2.768-1.844-4.524  c0.741-1.758,2.768-2.583,4.524-1.842c2.067,0.874,4.003,1.923,5.784,3.124c1.836,1.238,3.535,2.639,5.078,4.182  s2.944,3.244,4.183,5.079c1.2,1.782,2.25,3.717,3.124,5.784c0.874,2.063,1.55,4.24,2.007,6.5C93.764,45.214,94,47.481,94,49.757  c0,2.274-0.236,4.544-0.688,6.78c-0.457,2.261-1.133,4.437-2.007,6.5c-0.874,2.066-1.924,4.002-3.124,5.783  c-1.237,1.836-2.64,3.537-4.183,5.08c-1.543,1.541-3.244,2.943-5.08,4.182C77.138,79.283,75.203,80.332,73.136,81.207z"/>
                    <path d="M46.121,17.202c-6.903,5.197-13.808,10.396-20.712,15.592H11.966C8.686,32.794,6,35.479,6,38.759v22.483  c0,3.281,2.686,5.965,5.966,5.965h13.442c6.904,5.197,13.81,10.395,20.712,15.592c3.147,2.367,6.793,1.988,6.793-2.121  c0-20.451,0-40.903,0-61.354C52.914,15.214,49.269,14.833,46.121,17.202z"/>
                </svg>
            </svg>
        </div>
    </body>
</html>
