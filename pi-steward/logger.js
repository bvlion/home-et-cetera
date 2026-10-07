var log4js = require('log4js');
var logger = exports = module.exports = {};

log4js.configure({
  appenders: { exec: { type: 'dateFile', filename: './logs/exec.log' } },
  categories: { default: { appenders: ['exec'], level: 'info' } }
});

logger.exec = log4js.getLogger('exec');
