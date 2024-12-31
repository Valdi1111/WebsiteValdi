/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */
// bootstrap
import "@CoreBundle/scss/global.scss";
import "bootstrap/dist/js/bootstrap";

// start the Stimulus application
import '@App/bootstrap';

import { registerReactControllerComponents } from '@symfony/ux-react';

registerReactControllerComponents(require.context('@CoreBundle/components/', true, /\.(j|t)sx?$/));