<?php

use nntmux\Forum;

if (!$page->users->isLoggedIn())
	$page->show403();

$id = $_GET["id"] + 0;

$forum = new Forum();
if ($page->isPostBack())
{
		$forum->add($id, $page->users->currentUserId(), "", $_POST["addMessage"]);
		header("Location:".WWW_TOP."/forumpost/".$id."#last");
		die();
}

$results = $forum->getPosts($id);
if (count($results) == 0)
{
	header("Location:".WWW_TOP."/forum");
	die();
}

$page->meta_title = "Forum Post";
$page->meta_keywords = "view,forum,post,thread";
$page->meta_description = "View forum post";

$page->smarty->assign('results', $results);
$page->smarty->assign('privateprofiles', ($page->settings->getSetting('privateprofiles') == 1) ? true : false );

$page->content = $page->smarty->fetch('forumpost.tpl');
$page->render();


