<?php

use Nette\Image;
use Nette\Utils\Strings;
use Nette\Application\BadRequestException;


class HomepagePresenter extends BasePresenter
{
  static $boards = array(
    'a'   => "Anime & Manga",
    'b'   => "Random",
    'c'   => "Anime/Cute",
    'd'   => "Hentai/Alternative",
    'e'   => "Ecchi",
    'g'   => "Technology",
    'gif' => "Animated GIF",
    'h'   => "Hentai",
    'hr'  => "High Resolution",
    'k'   => "Weapons",
    'm'   => "Mecha",
    'o'   => "Auto",
    'p'   => "Photo",
    'r'   => "Request",
    's'   => "Sexy Beautiful Women",
    't'   => "Torrents",
    'u'   => "Yuri",
    'v'   => "Video Games",
    'vg'  => "Video Game Generals",
    'w'   => "Anime/Wallpapers",
    'wg'  => "Wallpapers/General",
    'i'   => "Oekaki",
    'ic'  => "Artwork/Critique",
    'r9k' => "ROBOT9001",
    'cm'  => "Cute/Male",
    'hm'  => "Handsome Men",
    'y'   => "Yaoi",
    '3'   => "3DCG",
    'adv' => "Advice",
    'an'  => "Animals & Nature",
    'cgl' => "Cosplay & EGL",
    'ck'  => "Food & Cooking",
    'co'  => "Comics & Cartoons",
    'diy' => "Do-It-Yourself",
    'fa'  => "Fashion",
    'fit' => "Health & Fitness",
    'hc'  => "Hardcore",
    'int' => "International",
    'jp'  => "Otaku Culture",
    'lit' => "Literature",
    'mlp' => "Pony",
    'mu'  => "Music",
    'n'   => "Transportation",
    'po'  => "Papercraft & Origami",
    'pol' => "Politically Incorrect",
    'sci' => "Science & Math",
    'soc' => "Social",
    'sp'  => "Sports",
    'tg'  => "Traditional Games",
    'toy' => "Toys",
    'trv' => "Travel",
    'tv'  => "Television & Film",
    'vp'  => "Pokemon",
    'wsg' => "Worksafe GIF",
    'x'   => "Paranormal",
  );


  private $cache;

  function __construct(Nette\Caching\Cache $cache)
  {
    $this->cache = $cache;
  }


  function renderDefault()
  {
    $this->template->boardsGroups = array_chunk(self::$boards, 28, true);
  }


  /** download $urls in paralel and returns downloaded data in array with corresponing indices */
  private function downloadUrls(array $urls)
  {
    $handles = $data = array();

    foreach ($urls as $key => $url) {
      $handles[$key] = $h = curl_init();
      curl_setopt($h, CURLOPT_URL, $url);
      curl_setopt($h, CURLOPT_HEADER, 0);
      curl_setopt($h, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($h, CURLOPT_ENCODING, "");
      curl_setopt($h, CURLOPT_TIMEOUT, 8);
    }
    
    $multi = curl_multi_init();
    
    foreach ($handles as $h)
      curl_multi_add_handle($multi, $h);
    
    $active = null;
    do {
      $mrc = curl_multi_exec($multi, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    
    while ($active && $mrc == CURLM_OK) {
      if (curl_multi_select($multi) != -1) {
        do {
          $mrc = curl_multi_exec($multi, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
      }
    }

    foreach ($handles as $k => $h) {
      curl_multi_remove_handle($multi, $h);
      $data[$k] = curl_multi_getcontent($h);
    }

    curl_multi_close($multi);

    return $data;
  }


  function renderImage($data)
  {
    $urls = explode("|", gzinflate(base64_decode(strtr($data, '_-', '/+'))));
    $urls = array_filter($urls, function ($url) { return Strings::match($url, '~^http://\d+.thumbs.4chan.org~'); });
    if (empty($urls))
      new BadRequestException;

    $file = "/img/" . md5($data);

    if (!file_exists(WWW_DIR . $file)) {
      $imagesData = $this->downloadUrls($urls);
      $imageFromString = function ($str) { return !$str ? Image::fromBlank(250, 250) : Image::fromString($str); };

      $op      = reset($imagesData);
      $replies = array_slice($imagesData, 1);

      $img = $imageFromString($op);
      $offset = 0;
      foreach ($replies as $rData) {
        $r = $imageFromString($rData);
        $mode = ($r->width > $r->height) ? Image::FILL : Image::FIT; 
        $r = $r->resize(62, 62, $mode);

        $c = $img->colorAllocateAlpha(255, 255, 255, 50);
        $img->filledRectangle($offset, $img->height, $offset + $r->width + 1, $img->height - 64, $c);

        $img->place($r, $offset, '100%', 100);
        $offset += $r->width + 2;
      }

      $img->save(WWW_DIR . $file, 70, Image::JPEG);
    }

    $this->redirectUrl($this->template->baseUrl . $file);
  }


  function renderBoard($board)
  {
    if (!isset(self::$boards[$board])) {
      throw new Nette\Application\BadRequestException();
    }

    $cache = $this->cache->derive("board");
    if (isset($cache[$board])) {
      $threads = $cache[$board];

    } else {
      $urls = array("http://boards.4chan.org/$board/");
      for ($i = 1; $i <= 5; $i++)
        $urls[] = "http://boards.4chan.org/$board/$i";

      $parser = new ThreadParser();
      
      $threads = array();
      foreach ($this->downloadUrls($urls) as $d) {
        $threads = array_merge($threads, $parser->parse($d));
      }

      foreach ($threads as $t) {
        $urls = array($t->image->thumb);
        foreach ($t->replies as $r)
          if (isset($r->image))
            $urls[] = $r->image->thumb;

        $t->slug = strtr(base64_encode(gzdeflate(join("|", $urls))), '/+', '_-');
      }

      $cache->save($board, $threads, array(
        Nette\Caching\Cache::EXPIRATION => '+ 8 minutes',
      ));
    }

    $this->template->threads = $threads;
    $this->template->board = $board;
    $this->template->boardsGroups = array_chunk(self::$boards, 28, true);
  }

}
