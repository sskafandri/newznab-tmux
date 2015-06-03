<?php
use newznab\utility\Utility;

$rc = new ReleaseComments;


// API functions.
$function = 's';
if (isset($_GET['t'])) {
	switch ($_GET['t']) {
		case 'd':
		case 'details':
			$function = 'd';
			break;
		case 'g':
		case 'get':
			$function = 'g';
			break;
		case 's':
		case 'search':
			$function = 's';
			break;
		case 'c':
		case 'caps':
			$function = 'c';
			break;
		case 'gn':
		case 'getnfo':
			$function = 'gn';
			break;
		case 'comm':
		case 'comments':
			$function = 'co';
			break;
		case 'commadd':
		case 'commentadd':
			$function = 'ca';
			break;
		case 'b':
		case 'book':
			$function = 'b';
			break;
		case 'mu':
			case 'music':
			$function = 'mu';
			break;
		case 'tv':
		case 'tvsearch':
			$function = 'tv';
			break;
		case 'm':
		case 'movie':
			$function = 'm';
			break;
		case 'u':
		case 'user':
			$function = 'u';
			break;
		case 'r':
		case 'register':
			$function = 'r';
			break;
		default:
			showApiError(202, 'No such function (' . $_GET['t'] . ')');
	}
} else {
	showApiError(200, 'Missing parameter (t)');
}

$uid = $apiKey = '';
$hosthash = '';
$catExclusions = [];
$maxRequests = 0;
// Page is accessible only by the apikey, or logged in users.
if ($users->isLoggedIn()) {
	$uid = $page->userdata['id'];
	$apiKey = $page->userdata['rsstoken'];
	$catExclusions = $page->userdata['categoryexclusions'];
	$maxRequests = $page->userdata['apirequests'];
} else {
	if ($function != 'c' && $function != 'r') {
		if (!isset($_GET['apikey'])) {
			showApiError(200, 'Missing parameter (apikey)');
		}
		$res = $users->getByRssToken($_GET['apikey']);
		$apiKey = $_GET['apikey'];

		if (!$res) {
			showApiError(100, 'Incorrect user credentials (wrong API key)');
		}

		$uid = $res['id'];
		$catExclusions = $users->getCategoryExclusion($uid);
		//
		// A hash of the users ip to record against the api hit
		//
		if ($page->site->storeuserips == 1)
			$hosthash = $users->getHostHash($_SERVER["REMOTE_ADDR"], $page->site->siteseed);
		$maxRequests = $res['apirequests'];
	}
}

$page->smarty->assign('uid', $uid);
$page->smarty->assign('rsstoken', $apiKey);

// Record user access to the api, if its been called by a user (i.e. capabilities request do not require a user to be logged in or key provided).
if ($uid != '') {
	$users->updateApiAccessed($uid);
	$apiRequests = $users->getApiRequests($uid);
	if ($apiRequests['num'] > $maxRequests) {
		showApiError(500, 'Request limit reached (' . $apiRequests['num'] . '/' . $maxRequests . ')');
	}
}

$releases = new \Releases(['Settings' => $page->settings]);

if (isset($_GET['extended']) && $_GET['extended'] == 1) {
	$page->smarty->assign('extended', '1');
}
if (isset($_GET['del']) && $_GET['del'] == 1) {
	$page->smarty->assign('del', '1');
}

// Output is either json or xml.
$outputXML = true;
if (isset($_GET['o'])) {
	if ($_GET['o'] == 'json') {
		$outputXML = false;
	}
}

switch ($function) {
	// Search releases.
	case 's':
		verifyEmptyParameter('q');
		$maxAge = maxAge();
		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);
		$categoryID = categoryid();
		$limit = limit();
		$offset = offset();

		if (isset($_GET['q'])) {
			$relData = $releases->search(
				$_GET['q'], -1, -1, -1, $categoryID, -1, -1, 0, 0, -1, -1, $offset, $limit, '', $maxAge, $catExclusions
			);
		} else {
			$totalRows = $releases->getBrowseCount($categoryID, $maxAge, $catExclusions);
			$relData = $releases->getBrowseRange($categoryID, $offset, $limit, '', $maxAge, $catExclusions);
			if ($totalRows > 0 && count($relData) > 0) {
				$relData[0]['_totalrows'] = $totalRows;
			}
		}

		printOutput($relData, $outputXML, $page, $offset);
		break;

	// Search tv releases.
	case 'tv':
		verifyEmptyParameter('q');
		verifyEmptyParameter('rid');
		verifyEmptyParameter('season');
		verifyEmptyParameter('ep');
		$maxAge = maxAge();
		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);
		$offset = offset();

		$relData = $releases->searchbyRageId(
			(isset($_GET['rid']) ? $_GET['rid'] : '-1'),
			(isset($_GET['season']) ? $_GET['season'] : ''),
			(isset($_GET['ep']) ? $_GET['ep'] : ''),
			$offset,
			limit(),
			(isset($_GET['q']) ? $_GET['q'] : ''),
			categoryid(),
			$maxAge
		);

		addLanguage($relData, $page->settings);
		printOutput($relData, $outputXML, $page, $offset);
		break;

	//
	// get nfo
	//
	case "gn":
		if (!isset($_GET["id"]))
			showApiError(200);

		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);

		$reldata = $releases->getByGuid($_GET["id"]);
		if (!$reldata)
			showApiError(300);

		$nfo = $releases->getReleaseNfo($reldata["id"], true);
		if (!$nfo)
			showApiError(300);

		$nforaw = Utility::cp437toUTF($nfo["nfo"]);
		$page->smarty->assign('release',$reldata);
		$page->smarty->assign('nfo',$nfo);
		$page->smarty->assign('nfoutf',$nforaw);

		if (isset($_GET["raw"]))
		{
			header("Content-type: text/x-nfo");
			header("Content-Disposition: attachment; filename=".str_replace(" ", "_", $reldata["searchname"]).".nfo");
			echo $nforaw;
			die();
		}
		else
		{
			$page->smarty->assign('rsstitle',"NFO");
			$page->smarty->assign('rssdesc',"NFO");
			$page->smarty->assign('rsshead',$page->smarty->fetch('rssheader.tpl'));
			$content = trim($page->smarty->fetch('apinfo.tpl'));

			printOutput($relData, $outputXML, $page, $offset);
		}
		break;

	//
	// get comments
	//
	case "co":
		if (!isset($_GET["id"]))
			showApiError(200);

		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);

		$data = $rc->getCommentsByGuid($_GET["id"]);
		if ($data)
			$reldata = $data;
		else
			$reldata = array();

		$page->smarty->assign('comments',$reldata);
		$page->smarty->assign('rsstitle',"API Comments");
		$page->smarty->assign('rssdesc',"API Comments");
		$page->smarty->assign('rsshead',$page->smarty->fetch('rssheader.tpl'));
		$content = trim($page->smarty->fetch('apicomments.tpl'));

		printOutput($relData, $outputXML, $page, $offset);

		break;

	//
	// add comment
	//
	case "ca":
		if (!isset($_GET["id"]))
			showApiError(200);

		if (!isset($_GET["text"]))
			showApiError(200);

		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);

		$reldata = $releases->getByGuid($_GET["id"]);
		if ($reldata)
		{
			$ret = $rc->addComment($reldata["id"], $reldata["gid"], $_GET["text"], $uid, $_SERVER['REMOTE_ADDR']);

			$content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
			$content.= "<commentadd id=\"".$ret."\" />\n";

			printOutput($content, $outputXML, $page);
		}
		else
		{
			showApiError(300);
		}

		break;

	//
	// search book releases
	//
	case "b":
		if (isset($_GET["author"]) && $_GET["author"]=="" && isset($_GET["title"]) && $_GET["title"]=="")
			showApiError(200);

		$maxage = -1;
		if (isset($_GET["maxage"]))
		{
			if ($_GET["maxage"]=="")
				showApiError(200);
			elseif (!is_numeric($_GET["maxage"]))
				showApiError(201);
			else
				$maxage = $_GET["maxage"];
		}

		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);

		$limit = 100;
		if (isset($_GET["limit"]) && is_numeric($_GET["limit"]) && $_GET["limit"] < 100)
			$limit = $_GET["limit"];

		$offset = 0;
		if (isset($_GET["offset"]) && is_numeric($_GET["offset"]))
			$offset = $_GET["offset"];
		$reldata = $releases->searchBook((isset($_GET["author"]) ? $_GET["author"] : ""), (isset($_GET["title"]) ? $_GET["title"] : ""), $offset, $limit, $maxage );

		$page->smarty->assign('offset',$offset);
		$page->smarty->assign('releases',$reldata);
		$page->smarty->assign('rsshead',$page->smarty->fetch('rssheader.tpl'));
		$output = trim($page->smarty->fetch('apiresult.tpl'));

		printOutput($relData, $outputXML, $page, $offset);
		break;

	//
	// search music releases
	//
	case "mu":
		if (isset($_GET["artist"]) && $_GET["artist"]=="" && isset($_GET["album"]) && $_GET["album"]=="")
			showApiError(200);
		$categoryId = array();
		if (isset($_GET["cat"]))
			$categoryId = explode(",",$_GET["cat"]);
		else
			$categoryId[] = -1;

		$maxage = -1;
		if (isset($_GET["maxage"]))
		{
			if ($_GET["maxage"]=="")
				showApiError(200);
			elseif (!is_numeric($_GET["maxage"]))
				showApiError(201);
			else
				$maxage = $_GET["maxage"];
		}

		$genreId = array();
		if (isset($_GET["genre"]))
			$genreId = explode(",",$_GET["genre"]);
		else
			$genreId[] = -1;

		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);

		$limit = 100;
		if (isset($_GET["limit"]) && is_numeric($_GET["limit"]) && $_GET["limit"] < 100)
			$limit = $_GET["limit"];

		$offset = 0;
		if (isset($_GET["offset"]) && is_numeric($_GET["offset"]))
			$offset = $_GET["offset"];
		$reldata = $releases->searchAudio((isset($_GET["artist"]) ? $_GET["artist"] : ""), (isset($_GET["album"]) ? $_GET["album"] : ""), (isset($_GET["label"]) ? $_GET["label"] : ""), (isset($_GET["track"]) ? $_GET["track"] : ""), (isset($_GET["year"]) ? $_GET["year"] : ""), $genreId, $offset, $limit, $categoryId, $maxage );

		$page->smarty->assign('offset',$offset);
		$page->smarty->assign('releases',$reldata);
		$page->smarty->assign('rsshead',$page->smarty->fetch('rssheader.tpl'));
		$output = trim($page->smarty->fetch('apiresult.tpl'));

		printOutput($relData, $outputXML, $page, $offset);
		break;

	// Search movie releases.
	case 'm':
		verifyEmptyParameter('q');
		verifyEmptyParameter('imdbid');
		$maxAge = maxAge();
		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);
		$offset = offset();

		$imdbId = (isset($_GET['imdbid']) ? $_GET['imdbid'] : '-1');

		$relData = $releases->searchbyImdbId(
			$imdbId,
			$offset,
			limit(),
			(isset($_GET['q']) ? $_GET['q'] : ''),
			categoryid(),
			$maxAge
		);

		addCoverURL($relData,
			function ($release) {
				return Utility::getCoverURL(['type' => 'movies', 'id' => $release['imdbid']]);
			}
		);

		addLanguage($relData, $page->settings);
		printOutput($relData, $outputXML, $page, $offset);
		break;

	// Get NZB.
	case 'g':
		if (!isset($_GET['id'])) {
			showApiError(200, 'Missing parameter (id is required for downloading an NZB)');
		}

		$relData = $releases->getByGuid($_GET['id']);
		if ($relData) {
			header(
				'Location:' .
				WWW_TOP .
				'/getnzb?i=' .
				$uid .
				'&r=' .
				$apiKey .
				'&id=' .
				$relData['guid'] .
				((isset($_GET['del']) && $_GET['del'] == '1') ? '&del=1' : '')
			);
		} else {
			showApiError(300, 'No such item (the guid you provided has no release in our database)');
		}
		break;

	// Get individual NZB details.
	case 'd':
		if (!isset($_GET['id'])) {
			showApiError(200, 'Missing parameter (id is required for downloading an NZB)');
		}

		$users->addApiRequest($uid, $_SERVER['REQUEST_URI'], $hosthash);
		$data = $releases->getByGuid($_GET['id']);

		$relData = [];
		if ($data) {
			$relData[] = $data;
		}

		printOutput($relData, $outputXML, $page, offset());
		break;

	// Capabilities request.
	case 'c':
		$category = new Category(['Settings' => $page->settings]);
		$page->smarty->assign('parentcatlist', $category->getForMenu());
		header('Content-type: text/xml');
		echo $page->smarty->fetch('apicaps.tpl');
		break;

	// Register request.
	case 'r':
		verifyEmptyParameter('email');

		if (!in_array((int)$page->site->registerstatus, [Sites::REGISTER_STATUS_OPEN, Sites::REGISTER_STATUS_API_ONLY])) {
			showApiError(104);
		}

		// Check email is valid format.
		if (!$users->isValidEmail($_GET['email'])) {
			showApiError(106);
		}

		// Check email isn't taken.
		$ret = $users->getByEmail($_GET['email']);
		if (isset($ret['id'])) {
			showApiError(105);
		}

		// Create username/pass and register.
		$username = $users->generateUsername($_GET['email']);
		$password = $users->generatePassword();

		// Register.
		$userDefault = $users->getDefaultRole();
		$uid = $users->signup(
			$username, $password, $_GET['email'], $_SERVER['REMOTE_ADDR'], $userDefault['id'], "", $userDefault['defaultinvites'], "", false, false, false, true
		);

		// Check if it succeeded.
		$userData = $users->getById($uid);
		if (!$userData) {
			showApiError(107);
		}

		header('Content-type: text/xml');
		echo
			"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n" .
			'<register username="' . $username .
			'" password="' . $password .
			'" apikey="' . $userdata['rsstoken'] .
			"\"/>\n";
		break;
}

/**
 * Display error/error code.
 * @param int    $errorCode
 * @param string $errorText
 */
function showApiError($errorCode = 900, $errorText = '')
{
	if ($errorText === '') {
		switch ($errorCode) {
			case 100:
				$errorText = 'Incorrect user credentials';
				break;
			case 101:
				$errorText = 'Account suspended';
				break;
			case 102:
				$errorText = 'Insufficient privileges/not authorized';
				break;
			case 103:
				$errorText = 'Registration denied';
				break;
			case 104:
				$errorText = 'Registrations are closed';
				break;
			case 105:
				$errorText = 'Invalid registration (Email Address Taken)';
				break;
			case 106:
				$errorText = 'Invalid registration (Email Address Bad Format)';
				break;
			case 107:
				$errorText = 'Registration Failed (Data error)';
				break;
			case 200:
				$errorText = 'Missing parameter';
				break;
			case 201:
				$errorText = 'Incorrect parameter';
				break;
			case 202:
				$errorText = 'No such function';
				break;
			case 203:
				$errorText = 'Function not available';
				break;
			case 300:
				$errorText = 'No such item';
				break;
			case 500:
				$errorText = 'Request limit reached';
				break;
			case 501:
				$errorText = 'Download limit reached';
				break;
			default:
				$errorText = 'Unknown error';
				break;
		}
	}

	header('Content-type: text/xml');
	header('X-newznab: API ERROR [' . $errorCode . '] ' . $errorText);
	exit("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<error code=\"$errorCode\" description=\"$errorText\"/>\n");
}

/**
 * Verify maxage parameter.
 * @return int
 */
function maxAge()
{
	$maxAge = -1;
	if (isset($_GET['maxage'])) {
		if ($_GET['maxage'] == '') {
			showApiError(201, 'Incorrect parameter (maxage must not be empty)');
		} elseif (!is_numeric($_GET['maxage'])) {
			showApiError(201, 'Incorrect parameter (maxage must be numeric)');
		} else {
			$maxAge = (int)$_GET['maxage'];
		}
	}
	return $maxAge;
}

/**
 * Verify cat parameter.
 * @return array
 */
function categoryid()
{
	$categoryID = array();
	$categoryID[] = -1;
	if (isset($_GET['cat'])) {
		$categoryIDs = $_GET['cat'];
		// Append Web-DL category id if HD present for SickBeard / NZBDrone compatibility.
		if (strpos($_GET['cat'], (string)Category::CAT_TV_HD) !== false &&
			strpos($_GET['cat'], (string)Category::CAT_TV_WEBDL) === false) {
			$categoryIDs .= (',' . Category::CAT_TV_WEBDL);
		}
		$categoryID = explode(',', $categoryIDs);
	}
	return $categoryID;
}

/**
 * Verify limit parameter.
 * @return int
 */
function limit()
{
	$limit = 100;
	if (isset($_GET['limit']) && is_numeric($_GET['limit']) && $_GET['limit'] < 100) {
		$limit = (int)$_GET['limit'];
	}
	return $limit;
}

/**
 * Verify offset parameter.
 * @return int
 */
function offset()
{
	$offset = 0;
	if (isset($_GET['offset']) && is_numeric($_GET['offset'])) {
		$offset = (int)$_GET['offset'];
	}
	return $offset;
}

/**
 * Print XML or JSON output.
 * @param array    $data   Data to print.
 * @param bool     $xml    True: Print as XML False: Print as JSON.
 * @param BasePage $page
 * @param int      $offset Offset for limit.
 */
function printOutput($data, $xml = true, $page, $offset = 0)
{
	if ($xml) {
		$page->smarty->assign('offset', $offset);
		$page->smarty->assign('releases', $data);
		$page->smarty->assign('rsshead',$page->smarty->fetch('rssheader.tpl'));
		header('Content-type: text/xml');
		echo trim($page->smarty->fetch('apiresult.tpl'));
	} else {
		header('Content-type: application/json');
		echo json_encode($data);
	}
}

/**
 * Check if a parameter is empty.
 * @param string $parameter
 */
function verifyEmptyParameter($parameter)
{
	if (isset($_GET[$parameter]) && $_GET[$parameter] == '') {
		showApiError(201, 'Incorrect parameter (' . $parameter . ' must not be empty)');
	}
}

function addCoverURL(&$releases, callable $getCoverURL)
{
	if ($releases && count($releases)) {
		foreach ($releases as $key => $release) {
			$coverURL = $getCoverURL($release);
			$releases[$key]['coverurl'] = $coverURL;
		}
	}
}

/**
 * Add language from media info XML to release search names.
 * @param array             $releases
 * @param \newznab\db\Settings $settings
 * @return array
 */
function addLanguage(&$releases, \newznab\db\Settings $settings)
{
	if ($releases && count($releases)) {
		foreach ($releases as $key => $release) {
			if (isset($release['id'])) {
				$language = $settings->queryOneRow(sprintf('SELECT audiolanguage FROM releaseaudio WHERE releaseid = %d', $release['id']));
				if ($language !== false) {
					$releases[$key]['searchname'] = $releases[$key]['searchname'] . ' ' . $language['audiolanguage'];
				}
			}
		}
	}
}