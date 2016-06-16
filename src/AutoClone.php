<?php

namespace Ares333\CurlMulti;

use phpQuery;

/**
 * Website copy, keep original directory structure(be supported by sounded reason)
 * phpQuery needed
 *
 * @author admin@phpdr.net
 *
 */
class AutoClone extends Base {
	// overwrite local file
	public $overwrite = false;
	// if download resource
	public $download = array (
			'pic' => array (
					'enable' => true
			),
			'zip' => array (
					'enable' => true,
					'withPrefix' => false
			)
	);
	public $logError = true;
	private $errorLog;
	private $startTime;
	// init url
	private $url;
	// absolute local dir
	private $dir;
	// processed url
	private $urlAdded = array ();
	// all site
	private $site = array ();
	// windows system flag
	private $isWin;

	/**
	 *
	 * @param Core $curlmulti
	 * @param string $url
	 * @param string $dir
	 */
	function __construct($url, $dir) {
		parent::__construct ();
		$this->startTime = time () - 1;
		if (is_array ( $url )) {
			$urlNew = array ();
			foreach ( $url as $k => $v ) {
				if (is_array ( $v )) {
					foreach ( $v as $k1 => $v1 ) {
						$path = '';
						if ('/' != $v1) {
							$path = '/' . ltrim ( $k1, '/' );
						}
						$urlNew [rtrim ( $k, '/' ) . $path] = $v1;
					}
				} elseif (is_string ( $v )) {
					$urlNew [] = $v;
				} else {
					user_error ( 'url is invalid', E_USER_ERROR );
				}
			}
			$url = $urlNew;
		} else {
			user_error ( 'url is invalid', E_USER_ERROR );
		}
		foreach ( $url as $k => $v ) {
			if (! $this->isUrl ( $k )) {
				user_error ( 'url is invalid, url=' . $k, E_USER_ERROR );
			}
		}
		if (! is_dir ( $dir )) {
			user_error ( 'dir not found, dir=' . $dir, E_USER_ERROR );
		}
		$this->url = $url;
		$this->dir = $dir;
		$this->isWin = (0 === strpos ( PHP_OS, 'WIN' ));		
	}

	/**
	 * start clone
	 */
	function start() {
		if (! empty ( $this->getCurl ()->cache ['dir'] )) {
			$this->errorLog = $this->getCurl ()->cache ['dir'] . '/autoCloneError.log';
		} else {
			$this->errorLog = __DIR__ . '/autoCloneError.log';
		}
		foreach ( $this->url as $k => $v ) {
			if ('/' != substr ( $k, - 1 )) {
				$this->getCurl ()->add ( array (
						'url' => $k,
						'opt' => array (
								CURLOPT_NOBODY => true
						)
				), function ($r) use($k, $v) {
					if ($k != $r ['info'] ['url']) {
						$this->url [$r ['info'] ['url']] = $v;
						unset ( $this->url [$k] );
					}
				} );
			}
		}
		$this->getCurl ()->start ();
		if (isset ( $this->getCurl ()->cbInfo ) && PHP_OS == 'Linux') {
			echo "\n";
		}
		foreach ( $this->url as $k => $v ) {
			$this->getCurl ()->add ( array (
					'url' => $k,
					'args' => array (
							'url' => $k,
							'file' => $this->url2file ( $k )
					)
			), array (
					$this,
					'cbProcess'
			) );
			$this->urlAdded [] = $k;
		}
		$this->getCurl ()->start ();
	}
	/**
	 * download and html callback
	 *
	 * @param array $r
	 * @param mixed $args
	 *
	 */
	function cbProcess($r, $args) {
		if (200 == $r ['info'] ['http_code']) {
			$urlDownload = array ();
			$urlParse = array ();
			if (isset ( $r ['content'] ) && 0 === strpos ( $r ['info'] ['content_type'], 'text' )) {
				$urlCurrent = $args ['url'];
				$pq = phpQuery::newDocumentHTML ( $r ['content'] );
				// base
				$base = $pq['base'];
				foreach ($base as $v) {
					$v = pq ( $v );
					$v->attr ( 'href', "" );
				}
				// css
				$list = $pq ['link[rel=stylesheet]'];
				foreach ( $list as $v ) {
					$v = pq ( $v );
					$url = $this->uri2url ( $v->attr ( 'href' ), $urlCurrent );
					$v->attr ( 'href', $this->cloneUrl2uri ( $url, $urlCurrent ) );
					$urlDownload [$url] = array (
							'type' => 'css'
					);
				}
				// script
				$script = $pq ['script'];
				foreach ( $script as $v ) {
					$v = pq ( $v );
					if (null != $v->attr ( 'src' )) {
						$url = $this->uri2url ( $v->attr ( 'src' ), $urlCurrent );
						$v->attr ( 'src', $this->cloneUrl2uri ( $url, $urlCurrent ) );
						$urlDownload [$url] = array ();
					}
				}
				// pic
				$pic = $pq ['img'];
				if ($this->download ['pic'] ['enable']) {
					foreach ( $pic as $v ) {
						$v = pq ( $v );
						if( false !== strpos( $v->attr ( 'src' ), 'data:' ) ) continue;
						$url = $this->uri2url ( $v->attr ( 'src' ), $urlCurrent );
						$v->attr ( 'src', $this->cloneUrl2uri ( $url, $urlCurrent ) );
						$urlDownload [$url] = array ();
					}
				} else {
					foreach ( $pic as $v ) {
						$v = pq ( $v );
						if( false !== strpos( $v->attr ( 'src' ), 'data:' ) ) continue;
						$v->attr ( 'src', $this->uri2url ( $v->attr ( 'src' ), $urlCurrent ) );
					}
				}
				// link xml
				$list = $pq ['link[type$=xml]'];
				foreach ( $list as $v ) {
					$v = pq ( $v );
					$url = $this->uri2url ( $v->attr ( 'href' ), $urlCurrent );
					if ($this->isProcess ( $url )) {
						$v->attr ( 'href', $this->cloneUrl2uri ( $url, $urlCurrent ) );
						$urlDownload [$url] = array ();
					}
				}
				// href
				$a = $pq ['a'];
				foreach ( $a as $v ) {
					$v = pq ( $v );
					$href = $v->attr ( 'href' );
					if (strtolower ( substr ( ltrim ( $href ), 0, 7 ) ) == 'mailto:') {
						continue;
					}
					if (strtolower ( substr ( ltrim ( $href ), 0, 11 ) ) == 'javascript:') {
						continue;
					}
					$url = $this->uri2url ( $href, $urlCurrent );
					if ($this->download ['zip'] ['enable'] && '.zip' == substr ( $href, - 4 )) {
						if ($this->download ['zip'] ['withPrefix']) {
							$isProcess = $this->isProcess ( $url );
						} else {
							$isProcess = true;
						}
						if ($isProcess) {
							$urlDownload [$url] = array ();
						}
					} else {
						$isProcess = $this->isProcess ( $url );
						if ($isProcess) {
							$urlParse [$url] = array ();
						}
					}
					if ($isProcess) {
						$v->attr ( 'href', $this->cloneUrl2uri ( $url, $urlCurrent ) );
					} else {
						$v->attr ( 'href', $url );
					}
				}
				$r ['content'] = $pq->html ();
				$path = $args ['file'];
				if (isset ( $path )) {
					if ($this->isWin) {
						$path = mb_convert_encoding ( $path, 'gbk', 'utf-8' );
					}
					if (false === file_put_contents ( $path, $r ['content'], LOCK_EX )) {
						user_error ( 'write file failed, file=' . $path, E_USER_WARNING );
					}
				}
				phpQuery::unloadDocuments ();
			} elseif ($args ['isDownload']) {
				if ('css' == $args ['type']) {
					$content = file_get_contents ( $args ['file'] );
					$uri = array ();
					// import
					preg_match_all ( '/@import\s+url\s*\((.+)\);/iU', $content, $matches );
					if (! empty ( $matches [1] )) {
						$uri = array_merge ( $uri, $matches [1] );
					}
					// url in css
					preg_match_all ( '/:\s*url\((\'|")?([^:]+?)\\1?\)/i', $content, $matches );
					if (! empty ( $matches [2] )) {
						$uri = array_merge ( $uri, $matches [2] );
					}
					foreach ( $uri as $v ) {
						$url = $this->uri2url ( $v, $r ['info'] ['url'] );
						$urlDownload [$url] = array (
								'type' => 'css'
						);
					}
				}
			}
			// add
			foreach ( array (
					'urlDownload',
					'urlParse'
			) as $v ) {
				foreach ( $$v as $k1 => $v1 ) {
					if (! in_array ( $k1, $this->urlAdded )) {
						$file = $this->url2file ( $k1 );
						if (null == $file) {
							continue;
						}
						$type = null;
						if (isset ( $v1 ['type'] )) {
							$type = $v1 ['type'];
						}
						$item = array (
								'url' => $k1,
								'file' => $file,
								'args' => array (
										'url' => $k1,
										'file' => $file,
										'type' => $type,
										'isDownload' => $v == 'urlDownload'
								)
						);
						if ($v == 'urlParse') {
							unset ( $item ['file'] );
						}
						$this->getCurl ()->add ( $item, array (
								$this,
								'cbProcess'
						) );
						$this->urlAdded [] = $k1;
					}
				}
			}
		} else {
			return array (
					'error' => 'http error ' . $r ['info'] ['http_code'],
					'cache' => array (
							'enable' => false
					)
			);
		}
	}

	/**
	 *
	 * {@inheritDoc}
	 * @see \Ares333\CurlMulti\Base::cbCurlFail()
	 */
	function cbCurlFail($error, $args) {
		if ($this->logError) {
			$err = $error ['error'];
			file_put_contents ( $this->errorLog, "Curl error $err[0]: $err[1], url=" . $error ['info'] ['url'] . "\n", FILE_APPEND );
		} else {
			parent::cbCurlFail ( $error, $args );
		}
	}

	/**
	 * is needed to process
	 *
	 * @param unknown $url
	 */
	private function isProcess($url) {
		$doProcess = false;
		foreach ( $this->url as $k1 => $v1 ) {
			if (0 === strpos ( $url, $k1 ) || $url . '/' == $k1) {
				if (! empty ( $v1 ['depth'] )) {
					$temp = $this->urlDepth ( $url, $k1 );
					if (isset ( $temp ) && $temp > $v1 ['depth']) {
						continue;
					}
				}
				$doProcess = true;
				break;
			}
		}
		return $doProcess;
	}

	/**
	 * calculate relative depth
	 *
	 * @param string $url
	 * @param string $urlBase
	 */
	private function urlDepth($url, $urlBase) {
		if ($this->isUrl ( $url ) && $this->isUrl ( $urlBase )) {
			if (0 === strpos ( $url, $urlBase )) {
				$path = ltrim ( substr ( $url, strlen ( $urlBase ) ), '/' );
				if (false !== $path) {
					$depth = 0;
					if (! empty ( $path )) {
						$depth = count ( explode ( '/', $path ) );
					}
					return $depth;
				}
			}
		}
	}

	/**
	 * url2uri for this class
	 *
	 * @param string $url
	 * @param string $urlCurrent
	 * @return string
	 */
	private function cloneUrl2uri($url, $urlCurrent) {
		$path = $this->url2uri ( $url, $urlCurrent );
		if (! isset ( $path )) {
			$dir2 = $this->urlDir ( $urlCurrent );
			$path1 = $this->getPath ( $url );
			$path2 = ltrim ( parse_url ( $dir2, PHP_URL_PATH ), '/' );
			$arr2 = array ();
			if (! empty ( $path2 )) {
				$arr2 = explode ( '/', rtrim ( $path2, '/' ) );
			}
			$path = '../';
			foreach ( $arr2 as $v ) {
				$path .= '../';
			}
			$path .= $path1;
		}
		if (empty($path)) $path = 'index.html';
		return $path;
	}

	/**
	 * compute local absolute path
	 *
	 * @param string $url
	 * @return string
	 */
	private function url2file($url) {
		$file = $this->getPath ( $url );
		$strrpos = strrpos ( $file, '#' );
		if (false !== $strrpos) {
			$file = substr ( $file, 0, $strrpos );
		}
		$file = $this->dir . '/' . $file;
		$dir = dirname ( $file );
		if ($this->isWin) {
			$dir = mb_convert_encoding ( $dir, 'gbk', 'utf-8' );
		}
		if (! is_dir ( $dir )) {
			if(true !== @mkdir ( $dir, 0755, true )) {
				if (is_dir($dir)) {
					// The directory was created by a concurrent process, so do nothing, keep calm and carry on
				} else {
					// There is another problem, we manage it (you could manage it with exceptions as well)
					$error = error_get_last();
					trigger_error($error['message'], E_USER_WARNING);
				}
			}
		}
		if (is_file ( $file )) {
			$mtime = filemtime ( $file );
			if ($mtime > $this->startTime || ! $this->overwrite) {
				$file = null;
			}
		}
		return $file;
	}

	/**
	 * relative local file path
	 *
	 * @param string $url
	 * @return string
	 */
	private function getPath($url) {
		$parse = parse_url ( trim ( $url ) );
		if (! isset ( $parse ['path'] )) {
			$parse ['path'] = '';
		}
		$ext = "";
		if('/' == substr( $parse ['path'], -1 )) {
			$parse ['path'] = rtrim ( $parse ['path'], '/' ) . '/index.html';

		}
		$ext = pathinfo ( $parse ['path'], PATHINFO_EXTENSION );
		if (empty ( $ext ) || is_numeric( $ext ) ) {
			$parse ['path'] = rtrim ( $parse ['path'], '/' ) . '.html';
		}
		$port = '';
		if (isset ( $parse ['port'] )) {
			$port = '_' . $port;
		}
		$path = $parse ['scheme'] . '_' . $parse ['host'] . $port . $parse ['path'];
		return $path;
	}
}