const config = require('flarum-webpack-config');
const path = require('path');

const webConfig = config();

webConfig.entry = {
    forum: path.resolve(__dirname, 'src/forum/index.js'),
    admin: path.resolve(__dirname, 'src/admin/index.js')
};

module.exports = webConfig;