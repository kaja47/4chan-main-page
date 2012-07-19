<?php

/**
 * This file is part of the Atrox toolbox
 *
 * Copyright (c) 2012, Karel Čížek (kaja47@k47.cz)
 *
 * @license New BSD License
 */

namespace Atrox;


class Matcher {

  private $f;

  function __construct($f) { $this->f = $f; }
  function __invoke()      { return call_user_func_array($this->f, func_get_args()); }

  function processHtml($html) { $dom = new \DOMDocument(); @$dom->loadHTML($html); return $this($dom); }
  function processXml ($xml)  { $dom = new \DOMDocument(); $dom->loadXML($xml);    return $this($dom); }
  function processDom ($dom)  { return $this($dom); }


  function fromHtml() { $self = $this; return function($html) use($self) { return $self->processHtml($html); }; }
  function fromXml()  { $self = $this; return function($xml) use($self) { return $self->processXml($xml); }; }


  /** @internal */
  static function defaultExtractor($n) { return $n instanceof \DOMNode ? $n->nodeValue : $n; }
  static function normalizeWhitespaces($n) { return trim(preg_replace('~\s+~', ' ', $n->nodeValue)); }
  //static function defaultExtractor($ns) { $res = ''; foreach ($ns as $n) { $res .= $n->nodeValue; } return $res; }


  /** Applies function $f to result of matcher (*after* extractor) */
  function andThen($f) {
    $self = $this;
    return new Matcher(function ($dom, $contextNode = null, $extractor = null) use($self, $f) {
      return $f($self($dom, $contextNode, $extractor));
    });
  }


  function andThenMulti($f) { // andThenMap ???
    return $this->andThen(function ($as) use($f) { return $as === null ? $as : array_map($f, $as); });
  }


  function flatten() {
    return $this->andThen(function ($arr) {
      $res = array();
      foreach ($arr as $k => $v) {
        if (is_array($v))
          foreach ($v as $kk => $vv) $res[$kk] = $vv;
        else
          $res[$k] = $v;
      }
      return $res;
    });
  }


  /** regexes without named patterns will return numeric array without key 0
   *  if result of previous matcher is array, it recursively applies regex on every element of that array
   */
  function regex($regex) {
    $f = function ($res) use($regex, & $f) { // &$f for anonymous recursion
      if ($res === null) {
        return null;

      } else if (is_string($res)) {
        preg_match($regex, $res, $m);
        if (count(array_filter(array_keys($m), 'is_string')) === 0) { // regex has no named subpatterns
          unset($m[0]);
        } else {
          foreach ($m as $k => $v) if (is_int($k)) unset($m[$k]);
        }
        return $m;

      } else if (is_array($res)) {
        $return = array();
        foreach ($res as $k => $v) $return[$k] = $f($v);
        return $return;

      } else {
        throw new \Exception("Method `regex' should be applied only to Matcher::single which returns string or array of strings");
      }
    };
    return $this->andThen($f);
  }


  /** defaultExtractor == null => use outer extractor
   *  @param string $basePath
   *  @param array|object $paths
   *  @param callable|null $defaultExtractor
   */
  static function multi($basePath, $paths = null, $defaultExtractor = null) {
    return new Matcher(function($dom, $contextNode = null, $extractor = null) use($basePath, $paths, $defaultExtractor) {
      $extractor = Matcher::_getExtractor($defaultExtractor, $extractor);

      if (is_string($basePath)) {
        $xpath = new \DOMXpath($dom);
        $matches = $xpath->query($basePath, $contextNode === null ? $dom : $contextNode);

      } elseif ($basePath instanceof Matcher) {
        $matches = $basePath($dom);
      
      } else {
        throw new \Exception("Matcher::multi - Invalid basePath. Expected string or marcher, ".gettype($val)." given");
      }

      $return = array();

      if (!$paths) {
        foreach ($matches as $m) $return[] = call_user_func($extractor, $m);

      } else {
        //$isObject = is_object($paths);
        //if ($isObject) $paths = (array) $paths;

        foreach ($matches as $m) {
          $res = Matcher::_extractPaths($dom, $m, $paths, $extractor);
          //if ($isObject) $res = (object) $res;
          $return[] = $res;
        }
      }

      return $return;
    });
  }


  /**
   *  @param string|array $path
   *  @param callable|null $defaultExtractor
   */
  static function single($path, $defaultExtractor = null) {
    return new Matcher(function ($dom, $contextNode = null, $extractor = null) use($path, $defaultExtractor) {
      $extractor = Matcher::_getExtractor($defaultExtractor, $extractor);
      $xpath = new \DOMXpath($dom);

      if (is_array($path)) {
        return Matcher::_extractPaths($dom, $contextNode, $path, $extractor);

      } else {
        return Matcher::_extractValue($extractor, $xpath->query($path, $contextNode === null ? $dom : $contextNode));
      }
    });
  }


  /* same as ::single(array)
  static function group($paths, $defaultExtractor = null) {
    return Matcher::multi('.', $paths, $defaultExtractor)->andThen('current');
  }
   */


  /** @internal */
  static function _getExtractor($defaultExtractor, $extractor) {
    if ($defaultExtractor !== null) return $defaultExtractor;
    else if ($extractor === null)   return 'Atrox\Matcher::defaultExtractor'; // use default extractor
    else                            return $extractor; // use outer extractor passed as explicit argument
  }


  /** @internal */
  static function _extractPaths($dom, $contextNode, $paths, $extractor) {
    $xpath = new \DOMXpath($dom);
    $return = array();

    foreach ($paths as $key => $val) {
      if (is_array($val)) { // path => array()
        $n = $xpath->query($key, $contextNode === null ? $dom : $contextNode)->item(0);
        $r = ($n === null) ? array_fill_keys(array_keys($val), null) : self::_extractPaths($dom, $n, $val, $extractor);
        $return = array_merge($return, $r); // todo: object result

      } elseif ($val instanceof Matcher || $val instanceof \Closure) { // key => multipath
        $return[$key] = $val($dom, $contextNode, $extractor);
      
      } elseif (is_string($val)) { // key => path
        $return[$key] = self::_extractValue($extractor, $xpath->query($val, $contextNode === null ? $dom : $contextNode));

      } else {
        throw new \Exception("Invalid path. Expected string, array or marcher, ".gettype($val)." given");
      }
    }

    return $return;
  }

  /** @internal */
  static function _extractValue($extractor, $matches) {
    return $matches->length === 0 ? null : call_user_func($extractor, $matches->item(0));
  }
}
