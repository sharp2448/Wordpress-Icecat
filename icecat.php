<?php
/**
 * @file
 * Holds basic icecat data.
 *
 * Plugin Name: Icecat data importer.
 * Plugin URI: http://harings.be
 * Description: This plugin allows you grab icecat data and attach it to your
 * post.
 * Author: tortelduif
 * Version: 4.5
 * Author URI: http://www.harings.be.
 */

// Start session.
session_start();
// Load function is plugin active.
if (!function_exists('is_plugin_active')) {
  require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// Include our file.
require_once plugin_dir_path(__FILE__) . 'Icecat/Icecat.php';

// Use the file.
use Icecat\Icecat;

/**
 * Admin notice function.
 *
 * Custom build for Icecat and wordpress.
 */
function icecat_admin_notice() {
  if (!empty($_SESSION['IcecatNotice'])) {
    foreach ($_SESSION['IcecatNotice'] as $key => $value) {
      // Error, updated.
      ?>
      <div class="<?php echo $value['type']; ?>">
          <p><strong>Icecat: </strong> <?php _e($value['message'], 'icecat'); ?></p>
      </div>
      <?php
      unset($_SESSION['IcecatNotice'][$key]);
    }
  }
  unset($_SESSION['IcecatNotice']);
}

/**
 * The following function makes the front end look good!
 *
 * It will add the icecat data to the content footer.
 */
function icecat_getdata($content, $newcontent = FALSE, $import = FALSE) {

  // Get our post, and do a base check. If not we stop this instantly.
  if ($newcontent && !is_array($newcontent)) {
    // Set the $post variable.
    $post = get_post($content);
    global $wp_query;
  }
  else {
    // If not a post or import. Do nothing.
    return;
  }

  // HERE ICECAT STARTS TO WORK.
  // Start the class.
  $icecat = new Icecat\Icecat();

  // Init our icecat config.
  $icecat->setConfig(
    get_option('icecat_username'),
    get_option('icecat_password')
  );

  // Set the language.
  $icecat->setLanguage(get_option('icecat_language'));

  // Set our product data.
  $icecat->setProductInfo(
    get_post_meta($post->ID, 'icecat_ean', TRUE),
    get_post_meta($post->ID, 'icecat_sku', TRUE),
    get_post_meta($post->ID, 'icecat_brand', TRUE)
  );

  // If we have an error, we should stop.
  if ($error_array = $icecat->hasErrors()) {
    // Our display error variable.
    $displayerror = TRUE;

    // The "file does not exist" error mostly happens when the content is not
    // avialable in the selected language. So if we have a fallback language,
    // we can adapt.
    if ($error_array['message'] == 'File does not exist.' && get_option('icecat_fallback') == 'off') {
      $error_array['message'] .= " The product might not be available in the following language: " . get_option('icecat_language') . ".";
    }
    elseif ($error_array['message'] == 'File does not exist.' && get_option('icecat_fallback') == 'on') {
      // Lets try again in english.
      $icecat->setLanguage('en');
      // Lets see if we have an error this time.
      if ($new_errors = $icecat->hasErrors()) {
        // We have an error once again.
        $_SESSION['IcecatNotice'][] = $new_errors;
      }
      else {
        // No errors this time, we can continue.
        $displayerror = FALSE;
      }
    }

    // Only if we display our error we continue.
    if ($displayerror) {
      // We set it to $error_array so we can alter the output before.
      $_SESSION['IcecatNotice'][] = $error_array;
      return;
    }
  }

  // Get our product data.
  // At this point there are no errors. We can start.
  // $postarr is the array we will push to the update function.
  $postarr['ID'] = $post->ID;

  // Product Title.
  $producttitle = $icecat->getAttribute('Title');
  // Product ID.
  $partnumber   = $icecat->getAttribute('Prod_id');
  // Supplier.
  $supplier     = $icecat->getSupplier();
  // Descriptions + custom alteration.
  $productinfo  = str_replace('\n', '<br />', $icecat->getLongDescription());
  // Get Category.
  $category     = str_replace('&', '-', $icecat->getCategory());
  // Images.
  $images       = $icecat->getImages();
  // Specs.
  $specs        = $icecat->getSpecs();

  // Our icecat insert to wordpress.
  //
  // 1. TAGGING
  // We'll be adding the tags (category) to our product.
  if (is_plugin_active('woocommerce/woocommerce.php') && get_option('icecat_set_category') == "on") {
    if (function_exists('sanitize_title') && !term_exists($category, 'product_cat')) {
      $slug = sanitize_title($category);
      // Create our array.
      $product_category_insert = wp_insert_term(
        $category,
        'product_cat',
        array(
          'description' => $category,
          'slug' => $slug,
        )
      );
      // Get our term id by name.
      $product_category_id = get_term_by('name', $category, 'product_cat');
      $update = TRUE;
      // The category is created, update is set to TRUE so we can add it to our
      // product.
    }
    else {
      if (!has_category($category, $post)) {
        // Category must be linked becouse it allready exists.
        $product_category_id = get_term_by('name', $category, 'product_cat');
        $update = TRUE;
      }
    }
  }
  // 2. DOWNLOADING IMAGES
  // Download and attach the images.
  if (is_array($images) && get_option('icecat_download_images') == "on" && $post->post_type == "product" && !has_post_thumbnail($post->ID)) {
    // Required include for woocommerce.
    // Also lets check we can atleast find an image!
    if (is_plugin_active('woocommerce/woocommerce.php') && isset($images[0]['high'])) {
      // Include the needed files..
      require_once ABSPATH . "wp-admin" . '/includes/image.php';
      require_once ABSPATH . "wp-admin" . '/includes/file.php';
      require_once ABSPATH . "wp-admin" . '/includes/media.php';
      // We use our producttitle as description.
      $desc = $producttitle != '' ? $producttitle : $post->post_title;
      // Specific, our first image as main image.
      $file = $images[0]['high'];
      // Download file to temp location.
      $tmp = download_url($file);
      // Set variables for storage.
      // fix file filename for query strings.
      preg_match('/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $file, $matches);
      $file_array['name'] = basename($matches[0]);
      $file_array['tmp_name'] = $tmp;
      // If error storing temporarily, unlink.
      if (is_wp_error($tmp)) {
        @unlink($file_array['tmp_name']);
        $file_array['tmp_name'] = '';
      }
      // Do the validation and storage stuff.
      $id = media_handle_sideload($file_array, $post->ID, $desc);
      // If error storing permanently, unlink.
      if (is_wp_error($id)) {
        @unlink($file_array['tmp_name']);
      }
      // Set the thumbnail.
      set_post_thumbnail($post->ID, $id);
      // Remove first image of our array.
      unset($images[0]);
      // Skip downloading additional images if it is an import. Would take a
      // long time.
      if (!$import || get_option('icecat_wp_all_import_multiimage') == 'on') {
        // Download images.
        $imgcount = 0;
        // Loop other images.
        foreach ($images as $pic) {
          // Store our url.
          $tmp = download_url($pic['high']);
          // If we have an error. Report it.
          if (is_wp_error($tmp)) {
            $_SESSION['IcecatNotice'][] = array(
              'message' => 'Error saving an image: <strong>' . $tmp->get_error_message() . '</strong>',
              'type' => 'error',
            );
            break;
          }
          // Match the filename.
          preg_match('/[^\?]+\.(jpg|JPG|jpe|JPE|jpeg|JPEG|gif|GIF|png|PNG)/', $pic['high'], $matches);
          $file_array = array(
            'name' => basename($matches[0]),
            'tmp_name' => $tmp,
          );
          // Add to downloadlist.
          $imglist[] = media_handle_sideload($file_array, $post->ID);
          // Count up.
          $imgcount++;
          // If we reached our maximum. Stop.
          if (get_option('icecat_image_amount') == $imgcount) {
            break;
          }
        }
      }
    }
  }
  // 3. SPECIFICATIONS
  // We create and add specifications to our product.
  $appendtobody = NULL;
  if (get_option('icecat_specs_body') == "on") {
    $appendtobody .= '<table id="icecat_spec_table">';
    $appendtobody .= '<thead><th>Feature</th><th>Feature Value</th></thead><tbody>';
  }
  // Create the table.
  $i = 0;
  $subcount = 0;
  // Check if array.
  if (is_array($specs)) {
    foreach ($specs as $spec) {

      // Set our product attributes.
      if (get_option('icecat_specs_attributes') == "on") {
        $badarr = array(' ', '&', '@', '"', "'", '/', '\\');
        $name = strtolower(str_replace($badarr, '', $spec['name']));
        $product_attributes[$subcount . $name] = array(
          // Make sure the 'name' is same as you have the attribute.
          'name' => htmlspecialchars(stripslashes($spec['name'])),
          'value' => $spec['data'],
          'position' => $subcount,
          'is_visible' => 1,
          'is_variation' => 0,
          'is_taxonomy' => 0,
        );
      }

      // Count up.
      $i++;

      // Our conditonal class.
      $class = $i == 2 ? 'even' : 'odd';

      // Set it correct.
      if (get_option('icecat_specs_body') == "on") {
        $appendtobody .= '<tr class="featurerow ' . $class . '">';
        $appendtobody .= '<td class="feature">' . $spec['name'] . '</td>';
        $appendtobody .= '<td class="featurevalue">' . $spec['data'] . '</td>';
        $appendtobody .= '</tr>';
      }
      // Reset if 2;
      if ($i == 2) {
        $i = 0;
      }

      // Subcount always counts up.
      $subcount++;
    }
    // Close the table.
    if (get_option('icecat_specs_body') == "on") {
      $appendtobody .= '</tbody>';
      $appendtobody .= '</table>';
    }
  }

  // 4. TITLE AND NAME
  // We set our product title and name.
  if (get_option('icecat_update_title') == 'on' && $producttitle) {
    $postarr['post_title'] = $producttitle;
    $postarr['post_name'] = $producttitle;
  }

  // 5. CATEGORY
  // If our category is set, add it.
  if (!empty($product_category_id->term_id)) {
    wp_set_object_terms($post->ID, $product_category_id->term_id, 'product_cat');
  }

  // 6. BODY
  // Update our body if we have data.
  if (get_option('icecat_update_body') == 'on') {
    if ($productinfo <> "") {
      $postarr['post_content'] = $productinfo . '<hr />' . $appendtobody;
    }
    else {
      $postarr['post_content'] = $content . '<hr />' . $appendtobody;
    }
  }

  // 7. SKU
  // Update sku.
  if (get_option('icecat_set_sku') == 'on' && isset($partnumber) && !empty($partnumber)) {
    update_post_meta($post->ID, '_sku', $partnumber);
  }

  // 8. ALL UPDATE FUNCTIONS
  // Update other fields.
  if ((get_option('icecat_update_body') == 'on' || get_option('icecat_update_title') == 'on') && $newcontent == TRUE) {
    remove_action('save_post', 'icecat_save_postdata');
    wp_update_post($postarr);

    // Set and check images.
    if (isset($imglist)) {

      foreach ($imglist as $key => $img) {
        // File got an error, unset it.
        if (is_wp_error($img)) {
          $_SESSION['IcecatNotice'][] = array('message' => 'Error saving an image: <strong>' . $img->get_error_message() . '</strong>', 'type' => 'error');
          unset($imglist[$key]);
        }
      }

      // If we still have data, add it to the system.
      if (!empty($imglist)) {
        update_post_meta($post->ID, '_product_image_gallery', implode(',', $imglist));
      }

    }
    // Set attributes.
    if (isset($product_attributes)) {
      update_post_meta($post->ID, '_product_attributes', $product_attributes);
    }
    add_action('save_post', 'icecat_save_postdata');
  }

  if (!$import) {
    $_SESSION['IcecatNotice'][] = array('message' => 'Icecat successfully completed <strong>' . $producttitle . '</strong>', 'type' => 'updated');
  }

}

/**
 * Set our seperate admin hook, and make it include a settings file.
 *
 * This way we can keep our code clean;
 */
function icecat_admin() {
  include plugin_dir_path(__FILE__) . 'includes/admin.php';
}

/**
 * Create our admin actions and settings.
 */
function icecat_admin_actions() {
  add_options_page("Icecat Data grabber", "Icecat Data grabber", "edit_pages", "icecat_data_grabber", "icecat_admin");
}

/**
 * Code for adding a field to the new:edit post page.
 */
function icecat_add_fields() {
  add_meta_box(
    'icecat_subform',
    __('Set IceCat Data', 'icecat_ean'),
    'icecat_inner_data_box',
    'post'
  );
  add_meta_box(
    'icecat_subform',
    __('Set IceCat Data', 'icecat_ean'),
    'icecat_inner_data_box',
    'page'
  );
  if (is_plugin_active('woocommerce/woocommerce.php') && get_option('icecat_woocommerce') == "on") {
    add_meta_box(
      'icecat_subform',
      __('Set IceCat Data', 'icecat_ean'),
      'icecat_inner_data_box',
      'product'
    );
  }
}

/**
 * Create the box itself.
 */
function icecat_inner_data_box($post) {
  // Required.
  wp_nonce_field(plugin_basename(__FILE__), 'icecat_noncename');
  echo '<div style="width: 100%; float: left;"><h3>Icecat Data</h3></div>';
  // Ean.
  echo '<label for="icecat_ean">';
  _e("Add an EAN (Required)", 'icecat_ean');
  echo '</label> ';
  echo '<input type="text" id="icecat_ean" name="icecat_ean" value="' . esc_attr(get_post_meta($post->ID, 'icecat_ean', TRUE)) . '" size="25" />';
  // Sku.
  echo '<label for="icecat_sku">';
  _e("Add an SKU (for icecat)", 'icecat_sku');
  echo '</label> ';
  echo '<input type="text" id="icecat_sku" name="icecat_sku" value="' . esc_attr(get_post_meta($post->ID, 'icecat_sku', TRUE)) . '" size="25" />';
  // Brand.
  echo '<label for="icecat_brand">';
  _e("Add a BRAND (for icecat)", 'icecat_brand');
  echo '</label> ';
  echo '<input type="text" id="icecat_ean" name="icecat_brand" value="' . esc_attr(get_post_meta($post->ID, 'icecat_brand', TRUE)) . '" size="25" />';
}

/**
 * The function for saving the data to the post:page.
 */
function icecat_save_postdata($post_id) {
  if ($_POST) {
    // Verify if this is an auto save routine.
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return;
    }
    // Verify this came from the our screen and with proper authorization.
    if (empty($_POST['icecat_noncename']) || !wp_verify_nonce($_POST['icecat_noncename'], plugin_basename(__FILE__))) {
      return;
    }
    // Check permissions.
    if ('page' == $_POST['post_type']) {
      if (!current_user_can('edit_page', $post_id)) {
        return;
      }
    }
    elseif ('post' == $_POST['post_type']) {
      if (!current_user_can('edit_post', $post_id)) {
        return;
      }
    }
    elseif ('product' == $_POST['post_type']) {
      if (!current_user_can('edit_post', $post_id)) {
        return;
      }
    }
    // Array of custom fields.
    $fields = array('ean', 'sku', 'brand');
    foreach ($fields as $field) {
      $new_ean_value = isset($_POST['icecat_' . $field]) ? esc_attr($_POST['icecat_' . $field]) : '';
      $ean_key = 'icecat_' . $field;
      $ean_value = get_post_meta($post_id, $ean_key, TRUE);
      if ($new_ean_value && '' == $ean_value) {
        add_post_meta($post_id, $ean_key, $new_ean_value, TRUE);
      }
      elseif ($new_ean_value && $new_ean_value != $ean_value) {
        update_post_meta($post_id, $ean_key, $new_ean_value);
      }
      elseif ('' == $new_ean_value && $ean_value) {
        delete_post_meta($post_id, $ean_key, $ean_value);
      }
    }
    // Download the icecatdata on save.
    icecat_getdata($post_id, TRUE);
  }
  elseif (current_filter() == 'pmxi_saved_post') {
    icecat_getdata($post_id, TRUE, TRUE);
  }
}

/*
 * Add our actions
 */
add_action('admin_menu', 'icecat_admin_actions');
add_action('add_meta_boxes', 'icecat_add_fields');
add_action('admin_notices', 'icecat_admin_notice');


if (get_option('icecat_on_save') == 'on') {
  add_action('save_post', 'icecat_save_postdata');
}

if (is_plugin_active('wp-all-import-pro/wp-all-import-pro.php') && get_option('icecat_wp_all_import') == 'on') {
  add_action('pmxi_saved_post', 'icecat_save_postdata', 10, 1);
}
