let mix = require('laravel-mix')
const path = require('path')

mix.setPublicPath('dist')
    .js('resources/js/field.js', 'js')
    .vue({ version: 3 })
    .css('resources/css/field.css', 'css')
    .webpackConfig({
        externals: { vue: 'Vue' },
        resolve: {
            alias: {
                'laravel-nova': path.join(
                    __dirname,
                    '../../vendor/laravel/nova/resources/js/mixins/packages.js'
                ),
            },
        },
    })
