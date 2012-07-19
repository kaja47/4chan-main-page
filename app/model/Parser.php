<?php

use Atrox\Matcher;

class ThreadParser
{
  function parse($data)
  {
    $details = array(
      'id'      => "div[starts-with(@class, 'postInfo') and not(starts-with(@class, 'postInfoM'))]/input/@name",
      'title'   => "div[starts-with(@class, 'postInfo') and not(starts-with(@class, 'postInfoM'))]//span[@class='subject']",
      'text'    => Matcher::single("blockquote", function ($n) { return trim(html_entity_decode(strip_tags(preg_replace('~(\<br\>\</br\>)+~', "\n", $n->c14n())))); }),
      'full'    => Matcher::single("div[@class='file'][.//a[starts-with(@class, 'fileThumb')]]/a[starts-with(@class, 'fileThumb')]/@href")->andThen(function ($x) { return $x ? ('http:'.$x) : $x; }),
      'thumb'   => Matcher::single("div[@class='file'][.//a[starts-with(@class, 'fileThumb')]]/a[starts-with(@class, 'fileThumb')]/img/@src")->andThen(function ($x) { return $x ? ('http:'.$x) : $x; }),
      'wh'      => Matcher::single("div[@class='file'][.//a[starts-with(@class, 'fileThumb')]]/a[starts-with(@class, 'fileThumb')]/img/@style")->regex('~height: (\d+)px; width: (\d+)px;~'),
    );

    $m = Matcher::multi("//div[@class='thread']", array(
      "op"      => Matcher::single(array(".//div[@class='post op']" => $details)),
      "replies" => Matcher::multi(".//div[@class='post reply']", $details),
      'counts'  => Matcher::single("span[@class='summary desktop']")->regex("~(?P<posts>\d+) posts? (?:and (?P<images>\d+) image repl(?:ies|y))? omitted\. Click Reply to view\.~"),
    ))->fromHtml(); 

    $threads = $m($data);

    $returnThreads = array();
    foreach ($threads as $thread) {
      $op = (object) $thread['op'];
      $op->threadId = $op->id;
      $op->image = (object) array(
        'full'  => $op->full,
        'thumb' => $op->thumb,
        'thumbWidth'  => $op->wh[2],
        'thumbHeight' => $op->wh[1],
      );
      $op->counts = (object)($thread['counts'] ? $thread['counts'] : array('posts' => 0, 'images' => 0));
      $op->counts->posts  += 1 + count($thread['replies']);
      $op->counts->images += 1;

      $op->replies = array();
      unset($op->full, $op->thumb);

      foreach ($thread['replies'] as $reply) {
        $reply = (object) $reply;
        $reply->threadId = $op->id;
        $reply->image = !$reply->full ? null : (object) array(
          'full'  => $reply->full,
          'thumb' => $reply->thumb,
          'thumbWidth'  => $op->wh[2],
          'thumbHeight' => $op->wh[1],
        );
        if ($reply->image !== null) {
          $op->counts->images += 1;
        }
        unset($reply->full, $reply->thumb);
        $op->replies[] = $reply;
      }
      $returnThreads[] = $op;
    }

    return $returnThreads;
  }
}
