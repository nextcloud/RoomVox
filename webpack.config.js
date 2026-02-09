const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')
const webpack = require('webpack')

const isDev = process.env.NODE_ENV !== 'production'

module.exports = {
    entry: {
        main: path.join(__dirname, 'src', 'main.js'),
    },
    output: {
        path: path.resolve(__dirname, 'js'),
        publicPath: '/js/',
        filename: 'resavox-[name].js',
        chunkFilename: 'resavox-[name].js',
        clean: true,
    },
    module: {
        rules: [
            {
                test: /\.vue$/,
                loader: 'vue-loader',
            },
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@babel/preset-env'],
                    },
                },
            },
            {
                test: /\.css$/,
                use: ['style-loader', 'css-loader'],
            },
            {
                test: /\.scss$/,
                use: ['style-loader', 'css-loader', 'sass-loader'],
            },
            {
                test: /\.(png|jpe?g|gif|svg|woff2?|eot|ttf)$/,
                type: 'asset/resource',
            },
        ],
    },
    plugins: [
        new VueLoaderPlugin(),
        new webpack.DefinePlugin({
            __VUE_OPTIONS_API__: JSON.stringify(true),
            __VUE_PROD_DEVTOOLS__: JSON.stringify(isDev),
            __VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
            appName: JSON.stringify('resavox'),
        }),
    ],
    resolve: {
        extensions: ['.js', '.vue'],
        alias: {
            vue$: 'vue/dist/vue.esm-bundler.js',
        },
        fallback: {
            string_decoder: false,
            buffer: false,
            path: false,
        },
    },
    optimization: {
        splitChunks: false,
    },
}
