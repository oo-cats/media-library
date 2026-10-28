const createConfig = require('flarum-webpack-config');
const config = createConfig();
config.devtool = false;
module.exports = config;
