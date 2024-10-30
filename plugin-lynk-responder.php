<?php
/*

Plugin Name: Lynk Responder
Description: Risponde alle chiamate http provenienti dall'app Lynk per la navigazione siti in mobile
Plugin URI: www.lynk.pro
Author: Nyx Web Solutions
Version: 1.0.4
Author URI: www.officinaweb.pro

*/

define( 'PLUGIN_FOLDER_URL', plugin_dir_url(dirname(__FILE__).'/plugin-lynk-responder.php'));
define('CLEANTEXT_MAXLENGHT', 100);

if( is_admin() ) {
  $lrsp = new LynkResponderSettingsPage($wpdb);
}

if(isset($_POST['getJoomlaArticolsForIOS'])) {
    try {
      $lrRsp = new LynkResponder($wpdb);
      $deletedArticlesIDS = (isset($_POST['deletedArticlesIDS']) ? json_decode($_POST['deletedArticlesIDS']) : NULL);
      $jsonResult = $lrRsp->generateJsonForIOS($deletedArticlesIDS);
      die($jsonResult);
    } catch (Exception $ex) {
      die($ex->getMessage());
    }
}

class LynkResponder
{
  private $options;
  private $wordpressDb;
    
  public function __construct($wpdb)
  {
    $this->options = get_option('lynk_responder_options');
    $this->wordpressDb = $wpdb;
  }
  
  private function generateImageLink($imageUrl)
  {
    if($imageUrl == NULL)
      return NULL;
    
    $siteBaseUrl = get_bloginfo('wpurl');
    $imageUrl = str_replace($siteBaseUrl, '', $imageUrl);
    if(strpos($imageUrl, "http") === 0 || strpos($imageUrl, "HTTP") === 0)
      return $imageUrl;
      
    if($this->stringLastChar($siteBaseUrl) != '/' && $this->stringFirstChar($imageUrl) != '/')
      return $siteBaseUrl.'/'.$imageUrl;
     
    return $siteBaseUrl.$imageUrl;
  }
  
  private function stringFirstChar($string)
  {
    return substr($string, 0, 1);
  }
  
  private function stringLastChar($string)
  {
    return substr($string, strlen($string)-1);
  }
    
  public function generateJsonForIOS($deletedArticlesIDS)
  {
    $mysqlquery = "select p.*, t.guid as featured_image
      from " . $this->wordpressDb->prefix . "term_relationships as r
      inner join " . $this->wordpressDb->prefix . "posts p on r.object_id = p.ID
      left outer join " . $this->wordpressDb->prefix . "postmeta pm on pm.post_id = p.ID and pm.meta_key = '_thumbnail_id'
      left outer join " . $this->wordpressDb->prefix . "posts t on t.ID = pm.meta_value
      where p.post_status <> 'trash' and r.term_taxonomy_id = " . $this->options['category_id'];
    
    if($deletedArticlesIDS != NULL)
    {
      $whereclause = ' and p.ID not in(';
      $append = '';
      foreach($deletedArticlesIDS as $id) {
        $whereclause .= $append . $id;
        $append = ', ';
      }
      $whereclause .= ')';
      $mysqlquery .= $whereclause;
    }

    $articles_order = $this->options['articles_order'];
    $sep_position = strrpos($articles_order, '_');
    if($sep_position)
    {
      $order_field = substr($articles_order, 0, $sep_position); 
      $order_direction = substr($articles_order, $sep_position+1); 
      $order_clause = " order by p.$order_field $order_direction";
      $mysqlquery .= $order_clause;
    }    

    $result = $this->wordpressDb->get_results($mysqlquery);
    
    $articles = array();
    $hide_images = $this->options['hide_images'];
    foreach($result as $a)
    {
      if($hide_images)
        $firstImageUrl = NULL;
      else if($a->featured_image)
        $firstImageUrl = $a->featured_image;
      else
        $firstImageUrl = $this->getFirstImageUrl($a->post_content, NULL);

      $article = array(
        "id" => $a->ID,
        "title" => $a->post_title,
        "imageUrl" => $firstImageUrl,
        "cleantext" => $this->prepareCleanText($a->post_content),
        "html" => $this->prepareHtmlText($a->post_content, $firstImageUrl)
      );
       
      array_push($articles, $article);
    }
    
    $default_image          = $this->generateImageLink($this->options['default_image']);
    $splashscreen_imageurl  = $this->generateImageLink($this->options['splash_screen']);
    $joomlasite = array(
      "title" => $this->options['title'],
      "splashScreenUrl" => $splashscreen_imageurl,
      "articles" => $articles,
      "defaultArticlesImage" => $default_image,
      "hideImages" => ($this->options['hide_images'] == 1)
    );
    
    return json_encode($joomlasite);
  }
  
  private function prepareHtmlText($introtext, $firstImageUrl)
  {
    $allowed_tags = '<br><BR><ul><UL><li><LI>';
    
    if($this->options['allow_link'])
      $allowed_tags .= '<a><A>';
    
    if($this->options['allow_parag'])
      $allowed_tags .= '<p><P>';
    
    if($this->options['allow_bold'])
      $allowed_tags .= '<b><B><strong><STRONG>';
    
    if($this->options['allow_italic'])
      $allowed_tags .= '<i><I><em><EM>';
    
    $html = "";
    
    if($firstImageUrl != NULL && !$this->options['hide_images'])
    {
      $html .= "<a href='" . $firstImageUrl . "'>";
      $html .= "<img style='float:left; width:50%; height:auto; margin-right:10px;margin-bottom:10px; vertical-align:middle' src='" . $this->generateImageLink($firstImageUrl) . "' />";
      $html .= "</a>";
    }
    
    $html .= "<span style='text-align:" . $this->options['textalign'] . ";font-family:" . $this->options['fontfamily'] . ";font-size:" . $this->options['fontsize'] . "'>";
    $html .= ($this->options['add_wordpress_parag'] ? $this->addWordpressParagraph(strip_tags($introtext, $allowed_tags)) : strip_tags($introtext, $allowed_tags));
    $html .= "</span>";
      
    return trim($html);
  }
  
  function prepareCleanText($text)
  {
    $text = trim(strip_tags($text));
    $text = html_entity_decode($text, ENT_NOQUOTES);
    $text = preg_replace('#(\\r\\n|\\n|\\r)#', '', $text);
    
    if(strlen($text) > CLEANTEXT_MAXLENGHT) { $text = substr($text, 0, CLEANTEXT_MAXLENGHT); }
    return $text;
  }
  
  private function addWordpressParagraph($html)
  {
    $html = '<p>' . $html . '</p>';
    $html = str_replace(array("\r\n\r\n", "\n\n", "\r\r"), '</p><p>', $html);
    return $html;
  }
  
  private function getFirstImageUrl($introtext, $default_value)
  {
    $result = $this->strSubstringBetweenStrings(
      $introtext,
      array('<img ','<IMG '),
      array('/>', '</img>','</IMG>')
    );
    
    if($result == null)
      return $default_value;
    
    $result = $this->strSubstringBetweenStrings(
      $result,
      array('src="','src=\''),
      array('"', '\'')
    );
    
    if($result == null)
      return $default_value;
    
    return $this->generateImageLink($result);
  }
  
  private function strSubstringBetweenStrings($source, $from, $to)
  {  
    $indexfrom = $this->strIndexFromForCut($source, $from);
    if($indexfrom == -1)
      return null;
    
    $indexto = $this->strIndexFromForCut(substr($source, $indexfrom), $to, true);
    if($indexto == -1)
      return null;
    
    return substr($source, $indexfrom, $indexto);
  }
  
  private function strIndexFromForCut($source, $to_find, $is_end = false)
  {
    foreach($to_find as $try)
    {
      $pos = strpos($source, $try);
      if(!($pos === false))
        return ($is_end ? ($pos) : ($pos + strlen($try))); 
    }
    
    return -1;
  }
  
  private function logOnFile($message, $type = 'debug')
  {
    error_log(date("Y.m.d H:i.s") . ": " . $message . "\n", 3, dirname(__FILE__) . '/' . $type . '.log');
  }
}

class LynkResponderSettingsPage
{
  private $options;
  private $wordpressDb;
  
  private $defaults;

  public function __construct($wpdb)
  {
    $defaults = array(
        'title' => get_bloginfo(),
        'allow_link' => 'on',
        'allow_parag' => 'on',
        'allow_bold' => 'on',
        'allow_italic' => 'on',
        'add_wordpress_parag' => 'on',
        'textalign' => 'left',
        'fontfamily' => 'Verdana',
        'fontsize' => '14'
      );
    
    $this->wordpressDb = $wpdb;
    add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
    add_action( 'admin_init', array( $this, 'page_init' ) );
    add_action( 'admin_init', array( $this, 'register_plugin_styles' ) );
    add_action( 'admin_init', array( $this, 'register_plugin_scripts' ) );
  }

  function register_plugin_styles() {
    wp_register_style('plugin-lynk-responder', plugins_url( '/css/style.css', __FILE__ ));
    wp_enqueue_style('plugin-lynk-responder');
  }

  function register_plugin_scripts() {
    wp_register_script('plugin-lynk-responder', plugins_url( '/js/script.js', __FILE__ ));
    wp_enqueue_script('plugin-lynk-responder');
  }

  public function add_plugin_page()
  {
    add_menu_page(
        'Lynk Responder Settings',
        'Lynk Responder',
        'manage_options', 
        'lynk_settings_page', // page
        array( $this, 'create_admin_page' ),
        plugins_url('/img/lynk_icon.png', __FILE__ ),
        85
    );

  }

  public function create_admin_page()
  {
      // Set class property
      $this->options = get_option('lynk_responder_options', $this->defaults);
      ?>
      <div class="wrap">
          <?php screen_icon(); ?>
          <h2>Lynk Responder Settings:</h2>
          <table id="main-settings-table">
            <tr>
              <td style="width:500px">
                <form method="post" action="options.php" enctype="multipart/form-data">
                <?php
                    // This prints out all hidden setting fields
                    settings_fields( 'main_option_group' ); // Option group
                    do_settings_sections( 'lynk_settings_page' ); // Page
                    submit_button(); 
                ?>
                </form>
              </td>
              <td>&nbsp;</td>
              <td style="width:auto">
                <?php $this->print_instructions(); ?>
              </td>
            </tr>
          </table>
      </div>
      <?php
  }

  public function page_init()
  {        
      register_setting(
          'main_option_group', // Option group
          'lynk_responder_options', // Option name
          array( $this, 'sanitize' ) // Sanitize
      );

      add_settings_section(
          'basic_setting_section', // ID
          'Basic Settings', // Title
          null, // Callback
          'lynk_settings_page' // Page
      );
      
      add_settings_section(
          'advance_setting_section', // ID
          'Advanced Settings', // Title
          null, // Callback
          'lynk_settings_page' // Page
      );

      add_settings_field(
          'title', // ID
          'Website title', // Title 
          array( $this, 'title_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Title of your website shown in Lynk app' // Description
      );  
    
      add_settings_field(
          'category_id', // ID
          'Articles category', // Title 
          array( $this, 'category_id_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Category you want to share' // Description
      );  
    
      add_settings_field(
          'splash_screen', // ID
          'Splash screen image:', // Title 
          array( $this, 'splash_screen_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          null // Description
      );
      
      add_settings_field(
          'default_image', // ID
          'Articles default image:', // Title 
          array( $this, 'default_image_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          null // Description
      );
      
      add_settings_field(
          'articles_order', // ID
          'Articles order:', // Title 
          array( $this, 'articles_order_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Order you want to follow showing articles on Lynk' // Description
      );
      
      add_settings_field(
          'allow_link', // ID
          'Keep links:', // Title 
          array( $this, 'allow_link_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Keep web links present in the articles?' // Description
      );
    
      add_settings_field(
          'allow_bold', // ID
          'Keep bold:', // Title 
          array( $this, 'allow_bold_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Keep bold text present in the articles?' // Description
      );
    
      add_settings_field(
          'allow_italic', // ID
          'Keep italic:', // Title 
          array( $this, 'allow_italic_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Keep italic text present in the articles?' // Description
      );
      
      add_settings_field(
          'allow_parag', // ID
          'Keep paragraphs:', // Title 
          array( $this, 'allow_parag_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Keep paragraphs present in the articles?' // Description
      );
    
      add_settings_field(
          'add_wordpress_parag', // ID
          'Wordpress paragraphs:', // Title 
          array( $this, 'add_wordpress_parag_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Add worpress paragraphs? (recommended)' // Description
      );
      
      add_settings_field(
          'hide_images', // ID
          'Hide images:', // Title 
          array( $this, 'hide_images_callback' ), // Callback
          'lynk_settings_page', // Page
          'basic_setting_section', // Section           
          'Hide article images?' // Description
      );  
    
      add_settings_field(
          'fontfamily', // ID
          'Articles text font:', // Title 
          array( $this, 'fontfamily_callback' ), // Callback
          'lynk_settings_page', // Page
          'advance_setting_section', // Section           
          'Choose font family for articles text' // Description
      );
    
      add_settings_field(
          'fontsize', // ID
          'Font size:', // Title 
          array( $this, 'fontsize_callback' ), // Callback
          'lynk_settings_page', // Page
          'advance_setting_section', // Section           
          'Choose font size for articles text' // Description
      );
      
      add_settings_field(
          'textalign', // ID
          'Text alignment:', // Title 
          array( $this, 'textalign_callback' ), // Callback
          'lynk_settings_page', // Page
          'advance_setting_section', // Section           
          'Choose text alignment for articles text' // Description
      );
    
  }

  public function sanitize( $input )
  {
    $files = $_FILES['lynk_responder_options'];
    foreach ( array_keys($files['name']) as $settings_field_id ) {
    
      $image = array(
        'name' => $files['name'][$settings_field_id],
        'type' => $files['type'][$settings_field_id],
        'size' => $files['size'][$settings_field_id],
        'tmp_name' => $files['tmp_name'][$settings_field_id],
        'error' => $files['error'][$settings_field_id],
      );
      
      if ($image['size'] && preg_match('/(jpg|jpeg|png|gif)$/', $image['type']) ) {
        $override = array('test_form' => false);
        $file = wp_handle_upload( $image, $override );
        $input[$settings_field_id] = $file['url'];
      }
    }
    
    return $input;
  }

  public function title_callback($descr)                { $this->textbox_callback('title', $descr); }
  public function hide_images_callback($descr)          { $this->checkbox_callback('hide_images', $descr); }
  public function default_image_callback($descr)        { $this->image_callback('default_image', $descr); }
  public function splash_screen_callback($descr)        { $this->image_callback('splash_screen', $descr); }
  public function allow_link_callback($descr)           { $this->checkbox_callback('allow_link', $descr); }
  public function allow_parag_callback($descr)          { $this->checkbox_callback('allow_parag', $descr); }
  public function allow_bold_callback($descr)           { $this->checkbox_callback('allow_bold', $descr); }
  public function allow_italic_callback($descr)         { $this->checkbox_callback('allow_italic', $descr); }
  public function add_wordpress_parag_callback($descr)  { $this->checkbox_callback('add_wordpress_parag', $descr); }
  public function fontfamily_callback($descr)           { $this->textbox_callback('fontfamily', $descr); }
  public function fontsize_callback($descr)             { $this->textbox_callback('fontsize', $descr); }
  
  public function textalign_callback($description) {
    $this->select_callback('textalign', array(
      array("value" => "left",    "label" => "Left"),
      array("value" => "justify", "label" => "Justify"),
      array("value" => "right",   "label" => "Right"),
    ), $description);
  }
  
  public function category_id_callback($description)
  {
    $categories = array();
    $rows = $this->wordpressDb->get_results("select * from " . $this->wordpressDb->prefix . "terms");
    foreach($rows as $row) {
      $categories[] = array("value" => $row->term_id, "label" => $row->name);
    }
    
    $this->select_callback('category_id', $categories, $description);
    
  }

  public function articles_order_callback($description)
  {
    $this->select_callback('articles_order', array(
      array("value" => "post_date_desc",      "label" => "Post date descending"),
      array("value" => "post_date_asc",       "label" => "Post date ascending"),
      array("value" => "post_modified_desc",  "label" => "Post last modification descending"),
      array("value" => "post_modified_asc",   "label" => "Post last modification ascending"),
      array("value" => "comment_count_desc",  "label" => "Comment number descending")
    ), $description);
  }

  
    
  public function textbox_callback($settings_field_id, $description = null)
  {
    printf(
        '<input type="text" id="'.$settings_field_id.'" name="lynk_responder_options['.$settings_field_id.']" value="%s" />',
        esc_attr( $this->options[$settings_field_id])
    );
    
    if($description != null)
      printf('<p class="description">'.$description.'</p>');
  }
  
  public function checkbox_callback($settings_field_id, $description = null)
  {
    printf('<input style="float:left;margin-top:7px" type="checkbox" id="'.$settings_field_id.'" name="lynk_responder_options['.$settings_field_id.']" ');
    checked($this->options[$settings_field_id], "on");
    printf(' />');
    
    if($description != null)
      printf('<p class="description" style="white-space:nowrap;padding-left:25px">'.$description.'</p>');
  }

  public function select_callback($settings_field_id, $select_options, $description = null)
  {
    printf('<select id="'.$settings_field_id.'" name="lynk_responder_options['.$settings_field_id.']">');
    
    foreach($select_options as $option) {
      printf('<option value="'.$option['value'].'" ');
      selected($this->options[$settings_field_id], $option['value']);
      printf(' >'.$option['label'].'</option>');
    }
    
    printf('</select>');
    
    if($description != null)
      printf('<p class="description">'.$description.'</p>');
  }
  
  public function image_callback($settings_field_id, $description = null)
  {
    $old_image = ($this->options != NULL && array_key_exists($settings_field_id, $this->options) ?
      $this->options[$settings_field_id] : NULL
    );
    
    if($old_image != null) {
      echo
       '<table>
          <tr>
            <td><input class="jImageChooser" type="radio" name="'.$settings_field_id.'_chooser" value="keep" checked="checked" /> Keep:</td>
            <td rowspan="3">
              <a title="Click to view full image" id="'.$settings_field_id.'" href="'.$old_image.'" target="_blank"><img style="width:70px" src="'.$old_image.'"  /></a>
              <input style="display:none" type="file" id="'.$settings_field_id.'" />
              <input type="hidden" id="hidden-'.$settings_field_id.'" name="lynk_responder_options['.$settings_field_id.']" value="'.$old_image.'" />
            </td>
          </tr>
          <tr>
            <td><input class="jImageChooser" type="radio" name="'.$settings_field_id.'_chooser" value="new" /> New:</td>
          </tr>
          <tr>
            <td><input class="jImageChooser" type="radio" name="'.$settings_field_id.'_chooser" value="delete" /> Delete:</td>
          </tr>
        </table>';
    } else {
      echo('<input type="file" id="'.$settings_field_id.'" name="lynk_responder_options['.$settings_field_id.']" />');
    }
    
    if($description != null)
      printf('<p class="description">'.$description.'</p>');
  }
  
  public function print_instructions()
  {
    echo "<table class='contentDocument'>
    <tr>
        <td class='headerDocument'>
            <img style='width:100%;margin-bottom:20px' src='".plugins_url('/img/istruzioni.png', __FILE__ )."' />
        </td>
    </tr>
    <tr>
        <td class='titleDocument'>What is the purpose of this plugin</td>
    </tr>
    <tr>
        <td class='descriptionDocument'>
            The plugin makes the site able to respond and to be read by Lynk smartphone application. More precisely makes visible a specific category of articles of the site for comfortable reading from the terminal.
        </td>
    </tr>
    <tr>
        <td class='titleDocument'>Configuring the basic plugin options</td>
    </tr>
     <tr>
        <td class='descriptionDocument'>
            <ul class='listDocument'>
                <li><b>Website title:</b><br />is the title that will appear in the app top bar, in the article list screen.</li>
                <li>
                    <b>Articles category:</b><br />here you have to select the articles category that will be displayed in the application. It's advisable to carefully select this category with interesting and well formatted articles, to be read comfortable by smartphones. To get best results, we suggest to create a new dedicated category, where copy and in case adjust carefully the articles you want to share. It's however possible use a category already used and displayed in the site.
                </li>
                <li>
                    <b>Splash screen image:</b><br />is the image that appears when the app is connected to your site. Is strongly recommended to use an image with a resolution of 2016 x 1136 and place the interesting elements of the splash screen in the middle frame with dimensions 800 x 1136 (download the sample file below)
                </li>
                <li>
                    <b>Articles default image:</b><br />is the image that will be displayed as thumbnail in the articles list for those articles that have no image inside. It is advisable to use a small picture (100x100 max). This image will be displayed for all articles if you select the 'Hide images' option.
                </li>
                <li>
                    <b>Articles order:</b><br />specifies which order has to follow Lynk showing your articles.
                </li>
                <li>
                    <b>Keep link / bold / italic:</b><br /> specifies which styles keep and which clean up in the text of the articles, before displaying them in the app.
                </li>
                <li>
                    <b>Keep paragraphs:</b><br />specifies if you want to keep the paragraphs (html p elements) in the articles text, before displaying them in the app.
                </li>
                <li>
                    <b>Add wordpress paragraphs:</b><br />specifies if you want to replace new-line-characters with paragraphs (html p elements) in the articles text, as wordpress natively already do in every articles in your website. Is recommended  to keep it enabled in first instance and check the result on smartphones before change it.
                </li>
                <li>
                    <b>Hide images:</b><br />if selected, no image taken from the articles will be display as thumbnail in the page list and as internal article image in article view.
                </li>
            </ul> 
         </td>
    </tr>
     <tr>
        <td class='titleDocument'>Configuring the advanced plugin options</td>
    </tr>
     <tr>
        <td class='descriptionDocument'>
            <ul class='listDocument'>
                <li>
                    <b>Articles text font:</b><br />specifies the font to be displayed articles, it is recommended to use system fonts (verdana, arial, times)
                </li>
                <li>
                    <b>Font size:</b><br />specifies the size of the font to displays the article
                </li>
                <li>
                    <b>Text alignment:</b><br />specifies the alignment of the text which displays the article
                </li>
                
            </ul>
        </td>
      </tr>
      <tr>
        <td class='titleDocument'>Strumenti</td>
      </tr>
      <tr>
        <td class='descriptionDocument'>
            <ul class='listDocument'>
                <li>
                    <b>Splash screen example:</b><br /><a href='".plugins_url('/img/splashscreen.zip', __FILE__ )."'>download</a> guidelines for splash screen image creation.
                </li>  
              </ul>
            </td>
        </tr>
        <tr>
            <td class='titleDocumentCanali'>Lynk channels</td>
        </tr>
        <tr>
        <td class='descriptionDocument'>
            <ul class='listDocument'>
                <li>
                    <b>Lynk channels subsciption:</b><br />subscribe your site in the <b>Lynk channels</b>, in this way all the <b>Lynk app</b> users will be able to see your address in the category you have chosen to belong and quickly connect with you.
                </li>
                <li>
                    <b>Subscribe now:</b><br />subscribe your site now visiting <a href='http://www.lynk.pro/iscriviti-ai-canali-lynk.html' target='_blank'>www.lynk.pro/iscriviti-ai-canali-lynk.html</a>
                </li>
              </ul>
            </td>
        </tr>
        <tr>
            <td class='titleDocumentSupport'>Support</td>
        </tr>
        <tr>
          <td class='descriptionDocument'>
            <ul class='listDocument'>
                <li>
                    <a href='http://www.lynk.pro/' title='Lynk' alt='Lynk' target='_blank' >www.lynk.pro</a>
                </li>
                <li>Want more features and options in the configuration of the plugin? <a href='http://www.lynk.pro/' title='Lynk' alt='Lynk' target='_blank' >contact us!</a></li>
            </ul>
          </td>
        </tr>
        <tr>
            <td class='titleDocument'>Download App Lynk</td>
        </tr>
        <tr>
        <td class='descriptionDocument'>
            <a href='https://itunes.apple.com/us/app/lynk/id737961993?mt=8&uo=4' target='itunes_store' style='display:inline-block; overflow:hidden; background:url(https://linkmaker.itunes.apple.com/htmlResources/assets/en_us//images/web/linkmaker/badge_appstore-lrg.png) no-repeat; width:135px; height:40px; @media only screen{background-image:url(https://linkmaker.itunes.apple.com/htmlResources/assets/en_us//images/web/linkmaker/badge_appstore-lrg.svg);}'></a>
        </td>
    </tr>
</table>";
  }

}
