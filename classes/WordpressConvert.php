<?php
/**
 * WordPress Converter for HTML Plugin
 * 
 * @copyright (c) 2012 NetLife Inc. All Rights Reserved.
 * http://www.netlife-web.com/
 * 
 * This work complements FLARToolkit, developed by Saqoosha as part of the Libspark project.
 *     http://www.libspark.org/wiki/saqoosha/FLARToolKit
 * FLARToolKit is @copyright (C)2008 Saqoosha,
 * and is ported from NyARToolKit, which is ported from ARToolKit.
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a @copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

$settings = explode(",", WORDPRESS_CONVERT_SETTING_CLASSES);
require_once(dirname(__FILE__)."/WordpressConvertSetting.php");
foreach($settings as $setting){
	require_once(dirname(__FILE__)."/WordpressConvertSetting".$setting.".php");
}
require_once(dirname(__FILE__)."/".WORDPRESS_CONVERT_CONTENT_MANAGER.".php");
require_once(dirname(__FILE__)."/ContentConverter.php");
$cartridgeNames = explode(",", WORDPRESS_CONVERT_CARTRIDGES);
foreach($cartridgeNames as $cartridgeName){
	if(!empty($cartridgeName) && file_exists(dirname(__FILE__)."/cartridges/".$cartridgeName."Cartridge.php")){
		require_once(dirname(__FILE__)."/cartridges/".$cartridgeName."Cartridge.php");
	}
}

/**
 * HTMLをWordPressテンプレートに変換するプラグインのメインクラス
 *
 * @package WordpressConvert
 * @author Naohisa Minagawa
 * @version 1.0
 */
class WordpressConvert {
	public static $convertError;
	
	public static function convertError(){
		return self::$convertError;
	}
	
	/**
	 * Initial
	 * @return void
	 */
	public static function init(){
		// エラーメッセージの初期化
		self::$convertError = "";
		
		// 初期化処理
		$settings = explode(",", WORDPRESS_CONVERT_SETTING_CLASSES);
		foreach($settings as $setting){
			add_action( 'admin_menu', array( "WordpressConvertSetting".$setting, 'init' ) );
		}
		
		// 初期表示のメニューを変更
		//if(empty($_GET["page"]) && preg_match("/\\/wp-admin\\//", $_SERVER["REQUEST_URI"]) > 0){
		//	wp_redirect(get_option('siteurl') . '/wp-admin/admin.php?page=wordpress_convert_menu');
		//}
	}
	
	/**
	 * 変換処理を必要に応じて実行する。
	 */
	public static function execute(){
		if( version_compare( PHP_VERSION, '5.3.0', '<' ) ){
			self::$convertError = __("PHP 5.3 or later is required for this plugin.", WORDPRESS_CONVERT_PROJECT_CODE);;
			return;
		}
		$contentManagerClass = WORDPRESS_CONVERT_CONTENT_MANAGER;
		$contentManager = new $contentManagerClass(get_option(WORDPRESS_CONVERT_PROJECT_CODE."_ftp_login_id"), get_option(WORDPRESS_CONVERT_PROJECT_CODE."_ftp_password"), get_option(WORDPRESS_CONVERT_PROJECT_CODE."_base_dir"));
		
		if($contentManager->isGlobalUpdate() || isset($_GET["reconstruct"])){
			$files = $contentManager->getList();
			if(is_dir($contentManager->getContentHome()) && !empty($files)){
				// 共通スタイルの自動生成
				$filename = $contentManager->getContentHome()."/style.css";
				$themeFile = $contentManager->getThemeFile($filename);
				$info = pathinfo($themeFile);
				if(!is_dir($info["dirname"])){
					@mkdir($info["dirname"], 0755, true);
				}
				if(($fp = @fopen($themeFile, "w+")) !== FALSE){
					fwrite($fp, "/* \r\n");
					fwrite($fp, "Theme Name: ".WORDPRESS_CONVERT_THEME_NAME."\r\n");
					fwrite($fp, "Description: ".__("Converted Theme by Wordpress Converter")."\r\n");
					fwrite($fp, "Author: ".__("NetLife Inc.")."\r\n");
					fwrite($fp, "Author URI: http://www.netlife-web.com/\r\n");
					fwrite($fp, "Version: 1.0\r\n");
					fwrite($fp, "License: GNU General Public License v2.0\r\n");
					fwrite($fp, "License URI: http://www.gnu.org/licenses/gpl-2.0.html\r\n");
					fwrite($fp, "\r\n");
					fwrite($fp, "This themes was generated by Wordpress Converter Plugin.\r\n");
					fwrite($fp, "Theme can not use except for servers which we select.\r\n");
					fwrite($fp, "In case, theme uses others, you may spent extra fee.\r\n");
					fwrite($fp, "\r\n");
					fwrite($fp, "*/\r\n");
					fclose($fp);
				}
				
				// アップされたテンプレートファイルを変換ルールに基づいて変換する。
				$converter = new ContentConverter();
				$cartridgeNames = explode(",", WORDPRESS_CONVERT_CARTRIDGES);
				foreach($cartridgeNames as $cartridgeName){
					if(!empty($cartridgeName) && class_exists($cartridgeName."Cartridge")){
						$className = $cartridgeName."Cartridge";
						$converter->addCartridge(new $className());
					}
				}
				$pageids = get_all_page_ids();
				foreach($pageids as $pageid){
					$code = get_post_meta($pageid, "_wp_page_code", true);
					if(!empty($code)){
						$converter->addPage($code, $pageid);
					}
				}
				// ページデータは事前に作成する。
				foreach($files as $filename){
					if(preg_match("/\\.html?$/i", $filename) > 0){
						$baseFileName = str_replace($contentManager->getContentHome(), "", $filename);
						switch($baseFileName){
							// 標準のファイルは固定ページテンプレートとして扱わない
							case "index.html":
							case "404.html":
							case "search.html":
							case "archive.html":
							case "taxonomy.html":
							case "category.html":
							case "tag.html":
							case "author.html":
							case "single.html":
							case "attachment.html":
							case "single-post.html":
							case "page.html":
							case "home.html":
							case "comments-popup.html":
								break;
							// それ以外のページは固定ページテンプレートとして扱う
							default:
								$baseFileCode = preg_replace("/\\.html?$/i", "", $baseFileName);
								if(substr($baseFileCode, 0, 1) != "_"){
									$pageid = $converter->getPageId($baseFileCode);
									if(empty($pageid)){
										// ページIDが未登録の場合には、ページを新規登録
										$pageid = wp_insert_post(array(
											"post_title" => $baseFileCode,
											"post_status" => "publish",
											"post_name" => $baseFileCode,
											"post_type" => "page",
										));
										add_post_meta($pageid, "_wp_page_template", $baseFileCode.".php", true);
										add_post_meta($pageid, "_wp_page_code", $baseFileCode, true);
										$converter->addPage($baseFileCode, $pageid);
									}
								}
								break;
						}
					}
				}
				foreach($files as $filename){
					if($contentManager->isUpdated($filename)){
						$themeFile = $contentManager->getThemeFile($filename);
						$baseFileName = str_replace($contentManager->getContentHome(), "", $filename);
						$info = pathinfo($themeFile);
						if(!is_dir($info["dirname"])){
							@mkdir($info["dirname"], 0755, true);
						}
						if(preg_match("/\\.(html?|css|js)$/i", $filename, $p) > 0){
							if(($fp = @fopen($themeFile, "w+")) !== FALSE){
								$content = $contentManager->getContent($filename);
								switch($p[1]){
									case "htm":
									case "html":
										if(substr($baseFileName, 0, 1) != "_"){
											switch($baseFileName){
												// 標準のファイルは固定ページテンプレートとして扱わない
												case "index.html":
												case "404.html":
												case "search.html":
												case "archive.html":
												case "taxonomy.html":
												case "category.html":
												case "tag.html":
												case "author.html":
												case "single.html":
												case "attachment.html":
												case "single-post.html":
												case "page.html":
												case "home.html":
												case "comments-popup.html":
													break;
												// それ以外のページは固定ページテンプレートとして扱う
												default:
													$baseFileCode = preg_replace("/\\.html?$/i", "", $baseFileName);
													fwrite($fp, "<?php\r\n");
													fwrite($fp, "/*\r\n");
													fwrite($fp, "Template Name: ".$baseFileCode."\r\n");
													fwrite($fp, "*/\r\n");
													fwrite($fp, "?>\r\n");
													$pageid = $converter->getPageId($baseFileCode);
													if(empty($pageid)){
														// ページIDが未登録の場合には、ページを新規登録
														$pageid = wp_insert_post(array(
															"post_title" => $baseFileCode,
															"post_status" => "publish",
															"post_name" => $baseFileCode,
															"post_type" => "page",
														));
														add_post_meta($pageid, "_wp_page_template", $baseFileCode.".php", true);
														add_post_meta($pageid, "_wp_page_code", $baseFileCode, true);
													}
													break;
											}
											fwrite($fp, $converter->convert($baseFileName, $content)->php());
										}else{
											fwrite($fp, $content);
											copy($themeFile, preg_replace("/\\.php$/i", ".html", $themeFile));
										}
										break;
									case "css":
										if(preg_match_all("/url\\(*([^\\)]+)\\)/", $content, $params) > 0){
											foreach($params[0] as $index => $source){
												$target = "url(".preg_replace("/\\/[^\\/]+\\/\\.\\.\\//", "/", get_theme_root_uri()."/".WORDPRESS_CONVERT_THEME_NAME."/".dirname($baseFileName)."/".$params[1][$index]).")";
												str_replace($source, $target, $content);
											}
										}
										fwrite($fp, $content);
										break;
									case "js":
										$content = preg_replace("/eval\\('bindobj\\.level = ' \\+ val\\);/", "eval('bindobj.level = 0');", $content);
										$content = preg_replace("/bindobj\\.siteroot = ''/", "bindobj.siteroot = '".get_theme_root_uri()."/".WORDPRESS_CONVERT_THEME_NAME."/'", $content);
										$content = preg_replace("/bindobj\\.dir = ''/", "bindobj.dir = '".get_theme_root_uri()."/".WORDPRESS_CONVERT_THEME_NAME."/'", $content);
										fwrite($fp, $content);
										break;
								}
								fclose($fp);
							}
						}else{
							copy($filename, $themeFile);
						}
					}
				}
				
				// 共通関数プログラムの自動生成
				$filename = $contentManager->getContentHome()."/functions.php";
				$themeFile = $contentManager->getThemeFile($filename);
				$info = pathinfo($themeFile);
				if(!is_dir($info["dirname"])){
					@mkdir($info["dirname"], 0755, true);
				}
				if(($fp = @fopen($themeFile, "w+")) !== FALSE){
					fwrite($fp, "<?php\r\n");
					$menus = $converter->getNavMenus();
					if(is_array($menus) && !empty($menus)){
						fwrite($fp, "if(function_exists('register_nav_menus')){\r\n");
						fwrite($fp, "register_nav_menus(array(\r\n");
						foreach($menus as $id => $name){
							if(!empty($id)){
								fwrite($fp, "'".$id."' => '".$name."',\r\n");
							}
						}
						fwrite($fp, "));\r\n");
						fwrite($fp, "}\r\n");
					}
					$widgets = $converter->getWidgets();
					if(is_array($widgets) && !empty($widgets)){
						fwrite($fp, "if(function_exists('register_sidebar')){\r\n");
						foreach($widgets as $id => $name){
							fwrite($fp, "register_sidebar(array(");
							if(!empty($id)){
								fwrite($fp, "'id' => '".$id."', ");
							}
							if(!empty($name)){
								fwrite($fp, "'name' => '".$name."'");
							}
							fwrite($fp, "));\r\n");
						}
						fwrite($fp, "}\r\n");
					}
					fwrite($fp, "function eyecatch_setup() {\r\n");
					fwrite($fp, "add_theme_support( 'post-thumbnails' );\r\n");
					fwrite($fp, "}\r\n");
					fwrite($fp, "add_action( 'after_setup_theme', 'eyecatch_setup' );\r\n");
					fwrite($fp, "function wp_list_paginate(){\r\n");
					fwrite($fp, "global \$wp_rewrite, \$wp_query, \$paged;\r\n");
					fwrite($fp, "\$paginate_base = get_pagenum_link(1);\r\n");
					fwrite($fp, "if (strpos(\$paginate_base, '?') || ! \$wp_rewrite->using_permalinks()) {\r\n");
					fwrite($fp, "\$paginate_format = '';\r\n");
					fwrite($fp, "\$paginate_base = add_query_arg('paged', '%#%');\r\n");
					fwrite($fp, "} else {\r\n");
					fwrite($fp, "\$paginate_format = (substr(\$paginate_base, -1 ,1) == '/' ? '' : '/') .user_trailingslashit('page/%#%/', 'paged');\r\n");
					fwrite($fp, "\$paginate_base .= '%_%';\r\n");
					fwrite($fp, "}\r\n");
					fwrite($fp, "\$pagination = array('base' => \$paginate_base, 'format' => \$paginate_format, 'total' => \$wp_query->max_num_pages, 'mid_size' => 5, 'current' => (\$paged ? \$paged : 1), 'prev_text' => '&laquo; '.__('Previous'), 'next_text' => __('Next').' &raquo;');\r\n");
					fwrite($fp, "echo paginate_links(\$pagination);\r\n");
					fwrite($fp, "}\r\n");
					fclose($fp);
				}
				
				// テンプレートのスクリーンショットファイルをコピー
				$screenshotFile = $contentManager->getThemeFile($contentManager->getContentHome()."screenshot.png");
				if(file_exists($contentManager->getThemeFile($contentManager->getContentHome()."bdflashinfo/thumbnail.png"))){
					@copy($contentManager->getThemeFile($contentManager->getContentHome()."bdflashinfo/thumbnail.png"), $screenshotFile);
				}elseif(file_exists($contentManager->getThemeFile($contentManager->getContentHome()."siteinfos/thumbnail.png"))){
					@copy($contentManager->getThemeFile($contentManager->getContentHome()."siteinfos/thumbnail.png"), $screenshotFile);
				}
				
				// ライセンスファイルをコピー
				$licenseFile = $contentManager->getThemeFile($contentManager->getContentHome()."license.txt");
				@copy(WORDPRESS_CONVERT_BASE_DIR."/license.txt", $licenseFile);
			}else{
				self::$convertError = __("Target HTML was not found.", WORDPRESS_CONVERT_PROJECT_CODE);
			}
		}else{
			if(!$contentManager->isAccessible()){
				self::$convertError = __("Account Authentication Failed.", WORDPRESS_CONVERT_PROJECT_CODE);
			}
		}
	}
	
	public static function header(){
		$professional = get_option("wordpress_convert_professional");
		if($professional == "1"){
			echo "<link href=\"".WORDPRESS_CONVERT_BASE_URL."/css/custom.css\" rel=\"stylesheet\" type=\"text/css\">";
			echo "<script type=\"text/javascript\">\r\n";
			echo "addLoadEvent(function(){\r\n";
			echo "if(typeof jQuery!=\"undefined\"){\r\n";
			echo "jQuery(\"body\").prepend(\"<div id=\\\"bwp-custommode\\\">カスタムモードで使用中　<a href=\\\"admin.php?page=wordpress_convert_dashboard&professional=0\\\">かんたんモードに戻る</a></div>\")\r\n";
			echo "}\r\n";
			echo "});\r\n";
			echo "</script>\r\n";
		}
		echo "<link href=\"".WORDPRESS_CONVERT_BASE_URL."/css/global.css\" rel=\"stylesheet\" type=\"text/css\">";
	}
	
	public function display(){
		if(get_option("wordpress_convert_site_closed") == "1"){
			header("HTTP/1.0 404 Not Found");
			echo "<html><title>".__("404 Not Found")."</title>";
			echo "<body><p align=center><img src=\"".WORDPRESS_CONVERT_BASE_URL."/images/404.gif\" border=\"0\" /><BR/>";
			echo "<a href=\"http://www.digitalstage.jp/weblife/\"><img src=\"".WORDPRESS_CONVERT_BASE_URL."/images/help_link.gif\" border=\"0\" /></a>";
			echo "</p></body></html>";
			exit;
		}
	}
	
	public function mailer_init($mailer){
		$mailer->Sender = $mailer->From;
	}

	function install(){
		// インストール時の処理
	}

	function uninstall(){
		// アンインストール時の処理
	}


}
?>