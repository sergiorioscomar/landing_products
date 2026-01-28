<?php

/**
 * Plugin Name: LP Products (Metabox)
 * Description: Productos para landing con metabox personalizado: titulo, descripcion e imagen + shortcode [lp_products].
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

class LP_Products
{

  const CPT = 'lp_product';
  const META_DESC = '_lp_desc';
  const META_IMG  = '_lp_image_id';

  public function __construct()
  {
    add_action('init', [$this, 'register_cpt']);

    add_action('add_meta_boxes', [$this, 'add_metabox']);
    add_action('save_post_' . self::CPT, [$this, 'save_metabox'], 10, 2);

    add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

    add_shortcode('lp_products', [$this, 'shortcode_products']);
  }

  public function register_cpt()
  {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Landing Products',
        'singular_name' => 'Landing Product',
        'menu_name' => 'Landing Products',
        'add_new_item' => 'Agregar nuevo producto',
        'edit_item' => 'Editar producto',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'menu_icon' => 'dashicons-cart',
      'supports' => ['title'], // uso title de wordpress
      'has_archive' => false,
      'rewrite' => false,
    ]);

    // Opcional: sacar el editor clásico y featured image si aparecieran por plugins/tema
    remove_post_type_support(self::CPT, 'editor');
    remove_post_type_support(self::CPT, 'thumbnail');
  }

  public function add_metabox()
  {
    add_meta_box(
      'lp_product_box',
      'Datos del producto',
      [$this, 'render_metabox'],
      self::CPT,
      'normal',
      'high'
    );

    // Oculta el metabox de "Imagen destacada" si el theme lo agrega.
    remove_meta_box('postimagediv', self::CPT, 'side');
  }

  public function render_metabox($post)
  {
    $desc = get_post_meta($post->ID, self::META_DESC, true);
    $img_id = (int) get_post_meta($post->ID, self::META_IMG, true);

    $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'medium') : '';
    $img_html = $img_id ? wp_get_attachment_image($img_id, 'medium', false, ['style' => 'max-width:100%;height:auto;border-radius:10px;']) : '';

    wp_nonce_field('lp_product_save', 'lp_product_nonce');
?>
    <style>
      .lp-field {
        margin-bottom: 14px;
      }

      .lp-field label {
        display: block;
        font-weight: 600;
        margin-bottom: 6px;
      }

      .lp-field input[type="text"],
      .lp-field textarea {
        width: 100%;
      }

      .lp-imgwrap {
        border: 1px dashed #ccd0d4;
        padding: 12px;
        border-radius: 12px;
        background: #fff;
      }

      .lp-actions {
        margin-top: 10px;
        display: flex;
        gap: 10px;
        align-items: center;
      }

      .lp-muted {
        color: #666;
        font-size: 12px;
        margin-top: 6px;
      }
    </style>

    <div class="lp-field">
      <label for="lp_desc">Descripción</label>
      <textarea id="lp_desc" name="lp_desc" rows="5" placeholder="Escribí una descripción corta..."><?php echo esc_textarea($desc); ?></textarea>
    </div>

    <div class="lp-field">
      <label>Imagen</label>
      <div class="lp-imgwrap">
        <div id="lp_image_preview">
          <?php echo $img_html ?: '<div class="lp-muted">No hay imagen seleccionada.</div>'; ?>
        </div>

        <input type="hidden" id="lp_image_id" name="lp_image_id" value="<?php echo esc_attr($img_id); ?>" />

        <div class="lp-actions">
          <button type="button" class="button button-primary" id="lp_select_image">
            Seleccionar imagen
          </button>
          <button type="button" class="button" id="lp_remove_image" <?php echo $img_id ? '' : 'disabled'; ?>>
            Quitar
          </button>
        </div>
      </div>
    </div>

    <?php
  }

  public function admin_assets($hook)
  {
    global $post;

    // Solo cargar en pantalla de edición/creación de nuestro CPT
    if (!in_array($hook, ['post.php', 'post-new.php'], true)) return;
    if (!$post || $post->post_type !== self::CPT) return;

    // Necesario para el Media Uploader
    wp_enqueue_media();

    // JS inline para abrir el selector de medios
    add_action('admin_footer', function () {
      global $post;
      if (!$post || $post->post_type !== self::CPT) return;
    ?>
      <script>
        (function() {
          let frame;

          const btnSelect = document.getElementById('lp_select_image');
          const btnRemove = document.getElementById('lp_remove_image');
          const inputId = document.getElementById('lp_image_id');
          const preview = document.getElementById('lp_image_preview');

          if (!btnSelect || !btnRemove || !inputId || !preview) return;

          btnSelect.addEventListener('click', function(e) {
            e.preventDefault();

            if (frame) {
              frame.open();
              return;
            }

            frame = wp.media({
              title: 'Seleccionar imagen del producto',
              button: {
                text: 'Usar esta imagen'
              },
              multiple: false
            });

            frame.on('select', function() {
              const attachment = frame.state().get('selection').first().toJSON();
              inputId.value = attachment.id;

              // usa tamaño medium si existe
              const url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;

              preview.innerHTML = '<img src="' + url + '" style="max-width:100%;height:auto;border-radius:10px;display:block;" />';
              btnRemove.disabled = false;
            });

            frame.open();
          });

          btnRemove.addEventListener('click', function(e) {
            e.preventDefault();
            inputId.value = '';
            preview.innerHTML = '<div class="lp-muted">No hay imagen seleccionada.</div>';
            btnRemove.disabled = true;
          });
        })();
      </script>
    <?php
    });
  }

  public function save_metabox($post_id, $post)
  {
    // Nonce
    if (!isset($_POST['lp_product_nonce']) || !wp_verify_nonce($_POST['lp_product_nonce'], 'lp_product_save')) return;

    // Autosave / permisos
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Guardar descripción en meta
    if (isset($_POST['lp_desc'])) {
      $desc = wp_kses_post($_POST['lp_desc']);
      update_post_meta($post_id, self::META_DESC, $desc);
    }

    // Guardar imagen (attachment_id) en meta
    $img_id = isset($_POST['lp_image_id']) ? (int) $_POST['lp_image_id'] : 0;
    if ($img_id > 0) update_post_meta($post_id, self::META_IMG, $img_id);
    else delete_post_meta($post_id, self::META_IMG);
  }

  public function shortcode_products($atts)
  {
    $atts = shortcode_atts([
      'limit' => 12,
      'cols' => 3,
      'class' => '',
    ], $atts, 'lp_products');

    $limit = max(1, (int)$atts['limit']);
    $cols  = min(6, max(1, (int)$atts['cols']));

    $q = new WP_Query([
      'post_type' => self::CPT,
      'posts_per_page' => $limit,
      'post_status' => 'publish',
      'orderby' => 'date',
      'order' => 'DESC',
      'no_found_rows' => true,
    ]);

    if (!$q->have_posts()) return '<div class="lp-products-empty">No hay productos cargados.</div>';

    ob_start();
    
    // Generar un ID único para este shortcode
    $unique_id = 'lp-products-' . uniqid();
    ?>
    <style>
      .<?php echo $unique_id; ?> {
        display: grid;
        gap: 16px;
        /* Mobile first: 1 columna */
        grid-template-columns: 1fr;
      }
      
      /* Tablet: 2 columnas */
      @media (min-width: 640px) {
        .<?php echo $unique_id; ?> {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }
      
      /* Desktop: columnas especificadas por el usuario */
      @media (min-width: 1024px) {
        .<?php echo $unique_id; ?> {
          grid-template-columns: repeat(<?php echo (int)$cols; ?>, minmax(0, 1fr));
        }
      }
    </style>
    <div class="lp-products <?php echo esc_attr($atts['class']); ?> <?php echo $unique_id; ?>">
      <?php while ($q->have_posts()) : $q->the_post(); ?>
        <?php
        $desc = get_post_meta(get_the_ID(), self::META_DESC, true);
        $img_id = (int) get_post_meta(get_the_ID(), self::META_IMG, true);
        $image_url = $img_id ? wp_get_attachment_image_url($img_id, 'large') : '';
        $title = get_the_title();
        $description = $desc ? wpautop($desc) : '';
        $link = get_permalink();
        ?>
        <article class="lp-product">
          <div class="bg-white rounded-lg shadow-[0_4px_20px_rgba(0,0,0,0.05)] overflow-hidden flex flex-col h-full group transition-transform hover:-translate-y-1 duration-300">
            <div class="aspect-video relative overflow-hidden bg-gray-100 shadow-[0_6px_18px_rgba(0,0,0,0.12)]">
              <?php if ($image_url) : ?>
                <img
                  src="<?php echo esc_url($image_url); ?>"
                  alt="<?php echo esc_attr($title); ?>"
                  class="w-full h-full object-cover object-center transform group-hover:scale-105 transition-transform duration-500"
                  loading="lazy">
              <?php else : ?>
                <div class="flex items-center justify-center h-full text-gray-400">
                  <span class="sr-only"><?php esc_html_e('No image', 'lp-products'); ?></span>
                  <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                  </svg>
                </div>
              <?php endif; ?>
            </div>

            <div class="p-6 flex-grow flex flex-col text-center">
              <h3 class="text-xl font-bold text-[#2E3A8C] mb-3 leading-tight">
                <?php echo esc_html($title); ?>
              </h3>

              <?php if (!empty($description)) : ?>
                <div class="text-gray-500 text-sm mb-6 flex-grow line-clamp-3">
                  <?php echo wp_kses_post($description); ?>
                </div>
              <?php endif; ?>

              <div class="mt-auto pt-2">
                <a href="<?php echo esc_url($link); ?>" class="inline-block bg-[#A3D900] text-white font-bold py-2.5 px-8 rounded-full hover:bg-[#8Cb800] transition-colors uppercase text-xs tracking-wider shadow-lg shadow-[#A3D900]/30 icon-button-hover relative overflow-hidden">
                  <span class="relative z-10"><?php esc_html_e('LEER MÁS', 'lp-products'); ?></span>
                </a>
              </div>
            </div>
          </div>
        </article>
      <?php endwhile;
      wp_reset_postdata(); ?>
    </div>
<?php
    return ob_get_clean();
  }
}

new LP_Products();
