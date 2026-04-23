const config = require('flarum-webpack-config');
const path = require('path');

const flarumConfig = config();

flarumConfig.entry = {
    admin: path.resolve(__dirname, './src/admin/index.js'),
    forum: path.resolve(__dirname, './src/forum/index.js')
};

module.exports = flarumConfig;