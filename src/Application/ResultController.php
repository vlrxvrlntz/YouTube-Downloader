<?php

namespace YoutubeDownloader\Application;

use YoutubeDownloader\VideoInfo\VideoInfo;

/**
 * The result controller
 */
class ResultController extends ControllerAbstract
{
	/**
	 * Excute the Controller
	 *
	 * @param string $route
	 * @param YoutubeDownloader\Application\App $app
	 *
	 * @return void
	 */
	public function execute()
	{
		$config = $this->get('config');
		$template = $this->get('template');
		$toolkit = $this->get('toolkit');

		if( ! isset($_GET['videoid']) )
		{
			$this->responseWithErrorMessage('No video id passed in');
		}

		$my_id = $_GET['videoid'];

		$youtube_provider = \YoutubeDownloader\Provider\Youtube\Provider::createFromConfigAndToolkit(
			$config,
			$toolkit
		);

		if ( $youtube_provider instanceOf \YoutubeDownloader\Cache\CacheAware )
		{
			$youtube_provider->setCache($this->get('cache'));
		}

		if ( $youtube_provider instanceOf \YoutubeDownloader\Http\HttpClientAware )
		{
			$youtube_provider->setHttpClient($this->get('httpclient'));
		}

		if ( $youtube_provider instanceOf \YoutubeDownloader\Logger\LoggerAware )
		{
			$youtube_provider->setLogger($this->get('logger'));
		}

		if ( $youtube_provider->provides($my_id) === false )
		{
			$this->responseWithErrorMessage('Invalid url');
		}

		if (isset($_GET['type']))
		{
			$my_type = $_GET['type'];
		}
		else
		{
			$my_type = 'redirect';
		}

		$template_data = [
			'app_version' => $this->getAppVersion(),
		];

		$video_info = $youtube_provider->provide($my_id);

		if ($video_info->getStatus() == 'fail')
		{
			$message = 'Error in video ID: ' . $video_info->getErrorReason();

			if ($config->get('debug'))
			{
				$message .= '<pre>' . var_dump($video_info) . '</pre>';
			}

			$this->responseWithErrorMessage($message);
		}

		if ( $my_type !== 'Download' )
		{
			/* In this else, the request didn't come from a form but from something else
			 * like an RSS feed.
			 * As a result, we just want to return the best format, which depends on what
			 * the user provided in the url.
			 * If they provided "format=best" we just use the largest.
			 * If they provided "format=free" we provide the best non-flash version
			 * If they provided "format=ipad" we pull the best MP4 version
			 *
			 * Thanks to the python based youtube-dl for info on the formats
			 *   							http://rg3.github.com/youtube-dl/
			 */
			$redirect_url = $this->getDownloadUrlByFormat($video_info, $_GET['format']);

			if ( $redirect_url !== null )
			{
				header("Location: $redirect_url");
			}

			exit;
		}

		switch ($config->get('ThumbnailImageMode'))
		{
			case 2:
				$template_data['show_thumbnail'] = true;
				$template_data['thumbnail_src'] = 'getimage.php?videoid=' . $my_id;
				$template_data['thumbnail_anchor'] = 'getimage.php?videoid=' . $my_id . '&sz=hd';
				break;
			case 1:
				$template_data['show_thumbnail'] = true;
				$template_data['thumbnail_src'] = $video_info->getThumbnailUrl();
				$template_data['thumbnail_anchor'] = 'getimage.php?videoid=' . $my_id . '&sz=hd';
				break;
			case 0:
			default:
				$template_data['show_thumbnail'] = false;
		}

		$my_title = $video_info->getTitle();
		$cleanedtitle = $video_info->getCleanedTitle();

		$template_data['video_title'] = $video_info->getTitle();

		if (count($video_info->getFormats()) == 0)
		{
			$this->responseWithErrorMessage(
				'No format stream map found - was the video id correct?'
			);
		}

		if ($config->get('debug'))
		{
			$debug1 = '';

			if ($config->get('multipleIPs') === true)
			{
				$debug1 .= 'Outgoing IP: ' . print_r($toolkit->getRandomIp($config), true);
			}

			$template_data['show_debug1'] = true;
			$template_data['debug1'] = @var_export($video_info, true);
		}

		/* create an array of available download formats */
		$avail_formats = $video_info->getFormats();

		if ($config->get('debug'))
		{
			$first_format = $avail_formats[0];
			$template_data['show_debug2'] = true;
			$template_data['debug2_expires'] = $first_format->getExpires();
			$template_data['debug2_ip'] = $first_format->getIp();
			$template_data['debug2_ipbits'] = $first_format->getIpbits();
		}

		$template_data['streams'] = [];
		$template_data['formats'] = [];
		$template_data['showBrowserExtensions'] = ( $toolkit->is_chrome() and $config->get('showBrowserExtensions') == true );

		/* now that we have the array, print the options */
		foreach ($avail_formats as $avail_format)
		{
			$directlink = $avail_format->getUrl();
			// $directlink = explode('.googlevideo.com/', $avail_format->getUrl());
			// $directlink = 'http://redirector.googlevideo.com/' . $directlink[1] . '&ratebypass=yes&gcr=sg';
			$directlink .= '&title=' . $cleanedtitle;

			$proxylink = 'download.php?mime=' . $avail_format->getType() . '&title=' . urlencode($my_title) . '&token=' . base64_encode($avail_format->getUrl());

			$size = $this->getSize($avail_format->getUrl(), $config, $toolkit);

			$template_data['streams'][] = [
				'show_direct_url' => ($config->get('VideoLinkMode') === 'direct' || $config->get('VideoLinkMode') === 'both'),
				'show_proxy_url' => ($config->get('VideoLinkMode') === 'proxy' || $config->get('VideoLinkMode') === 'both'),
				'direct_url' => $directlink,
				'proxy_url' => $proxylink,
				'type' => $avail_format->getType(),
				'quality' => $avail_format->getQuality(),
				'size' => $toolkit->formatBytes($size),
			];
		}

		foreach ($video_info->getAdaptiveFormats() as $avail_format)
		{
			$directlink = $avail_format->getUrl();
			// $directlink = explode('.googlevideo.com/', $avail_format->getUrl());
			// $directlink = 'http://redirector.googlevideo.com/' . $directlink[1] . '&ratebypass=yes&gcr=sg';
			$directlink .= '&title=' . $cleanedtitle;

			$proxylink = 'download.php?mime=' . $avail_format->getType() . '&title=' . urlencode($my_title) . '&token=' . base64_encode($avail_format->getUrl());

			$size = $this->getSize($avail_format->getUrl(), $config, $toolkit);

			$template_data['formats'][] = [
				'show_direct_url' => ($config->get('VideoLinkMode') === 'direct' || $config->get('VideoLinkMode') === 'both'),
				'show_proxy_url' => ($config->get('VideoLinkMode') === 'proxy' || $config->get('VideoLinkMode') === 'both'),
				'direct_url' => $directlink,
				'proxy_url' => $proxylink,
				'type' => $avail_format->getType(),
				'quality' => $avail_format->getQuality(),
				'size' => $toolkit->formatBytes($size),
			];
		}

		if($config->get('MP3Enable'))
		{
			$mp3_url = sprintf(
				'download.php?mime=audio/mp3&token=%s&title=%s&getmp3=true',
				base64_encode($my_id),
				$cleanedtitle
			);

			$template_data['showMP3Download'] = true;
			$template_data['mp3_download_url'] = $mp3_url;
			$template_data['mp3_download_quality'] = $config->get('MP3Quality');
		}

		echo $template->render('getvideo.php', $template_data);
	}

	/**
	 * Get the download url for a specific format
	 *
	 * @param array $avail_formats
	 * @param string $format
	 * @return string|null
	 */
	private function getDownloadUrlByFormat(VideoInfo $video_info, $format)
	{
		$target_formats = '';

		switch ($format)
		{
			case "best":
				/* largest formats first */
				$target_formats = ['38', '37', '46', '22', '45', '35', '44', '34', '18', '43', '6', '5', '17', '13'];
				break;
			case "free":
				/* Here we include WebM but prefer it over FLV */
				$target_formats = ['38', '46', '37', '45', '22', '44', '35', '43', '34', '18', '6', '5', '17', '13'];
				break;
			case "ipad":
				/* here we leave out WebM video and FLV - looking for MP4 */
				$target_formats = ['37', '22', '18', '17'];
				break;
			default:
				/* If they passed in a number use it */
				if (is_numeric($format))
				{
					$target_formats[] = $format;
				}
				else
				{
					$target_formats = ['38', '37', '46', '22', '45', '35', '44', '34', '18', '43', '6', '5', '17', '13'];
				}
				break;
		}

		/* Now we need to find our best format in the list of available formats */
		$best_format = '';

		$avail_formats = $video_info->getFormats();

		for ($i = 0; $i < count($target_formats); $i++)
		{
			for ($j = 0; $j < count($avail_formats); $j++)
			{
				$format = $avail_formats[$j];
				if ($target_formats[$i] == $format->getItag())
				{
					$best_format = $j;
					break 2;
				}
			}
		}

		$redirect_url = null;

		if ( $best_format === '' )
		{
			return null;
		}

		$best_format = $avail_formats[$best_format];

		$redirect_url = $best_format->getUrl();

		if ( ! empty($redirect_url) )
		{
			$redirect_url .= '&title=' . $video_info->getCleanedTitle();
		}

		return $redirect_url;
	}
}
