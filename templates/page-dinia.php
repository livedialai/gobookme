<?php
/**
 * Template Name: Dinia Page
 * Description: Eigenes Template für Dinia-Shortcode-Seiten (Registrierung, Konto)
 *
 * @package Dinia
 */

get_header();
?>
<div class="wp-site-blocks">
    <main class="wp-block-group is-layout-flow" style="padding: 2rem 1rem;">
        <?php
        while ( have_posts() ) {
            the_post();
            the_content();
        }
        ?>
    </main>
</div>
<?php
get_footer();
