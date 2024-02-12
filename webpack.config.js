const Encore = require('@symfony/webpack-encore');
const path = require('path');

// Manually configure the runtime environment if not already configured yet by the "encore" command.
// It's useful when you use tools that rely on webpack.config.js file.
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .addAliases({
        '@CoreBundle': path.resolve(__dirname, 'src/CoreBundle/assets/'),
        '@AnimeBundle': path.resolve(__dirname, 'src/AnimeBundle/assets/'),
        '@BooksBundle': path.resolve(__dirname, 'src/BooksBundle/assets/'),
        '@VideosBundle': path.resolve(__dirname, 'src/VideosBundle/assets/'),
    })
    // directory where compiled assets will be stored
    .setOutputPath('public/build/')
    // public path used by the web server to access the output path
    .setPublicPath('/build')
    // only needed for CDN's or subdirectory deploy
    //.setManifestKeyPrefix('build/')

    .copyFiles([
        {from: path.resolve(__dirname, 'src/AnimeBundle/public/'), to: 'anime/[path][name].[ext]'},
        {from: path.resolve(__dirname, 'src/BooksBundle/public/'), to: 'books/[path][name].[ext]'},
        {from: path.resolve(__dirname, 'src/CoreBundle/public/'), to: 'core/[path][name].[ext]'},
        {from: path.resolve(__dirname, 'src/VideosBundle/public/'), to: 'videos/[path][name].[ext]'},
    ])

    /*
     * ENTRY CONFIG
     *
     * Each entry will result in one JavaScript file (e.g. app.js)
     * and one CSS file (e.g. app.css) if your JavaScript imports CSS.
     */
    .addEntry('core', '@CoreBundle/app.js')
    .addEntry('anime', '@AnimeBundle/app.js')
    .addEntry('books', '@BooksBundle/app.js')
    .addEntry('videos', '@VideosBundle/app.js')
    //.addEntry('books', './assets/app.js')

    // When enabled, Webpack "splits" your files into smaller pieces for greater optimization.
    .splitEntryChunks()

    // will require an extra script tag for runtime.js
    // but, you probably want this, unless you're building a single-page app
    .enableSingleRuntimeChunk()

    /*
     * FEATURE CONFIG
     *
     * Enable & configure other features below. For a full
     * list of features, see:
     * https://symfony.com/doc/current/frontend.html#adding-more-features
     */
    .cleanupOutputBeforeBuild()
    .enableBuildNotifications()
    .enableSourceMaps(!Encore.isProduction())
    // enables hashed filenames (e.g. app.abc123.css)
    .enableVersioning(Encore.isProduction())

    // configure Babel
    // .configureBabel((config) => {
    //     config.plugins.push('@babel/a-babel-plugin');
    // })

    // enables and configure @babel/preset-env polyfills
    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = '3.23';
    })

    // enables Sass/SCSS support
    .enableSassLoader()

    // uncomment if you use TypeScript
    //.enableTypeScriptLoader()

    // uncomment if you use React
    .enableReactPreset()

// uncomment to get integrity="..." attributes on your script & link tags
// requires WebpackEncoreBundle 1.4 or higher
//.enableIntegrityHashes(Encore.isProduction())

// uncomment if you're having problems with a jQuery plugin
//.autoProvidejQuery()
;

module.exports = Encore.getWebpackConfig();
