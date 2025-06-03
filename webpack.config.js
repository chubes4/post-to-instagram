const defaultConfig = require('@wordpress/scripts/config/webpack.config');

// Function to recursively find and modify babel-loader options
function disableBabelCache(rules) {
    return rules.map(rule => {
        if (rule.oneOf) {
            // If there's a oneOf array, recurse into it
            return { ...rule, oneOf: disableBabelCache(rule.oneOf) };
        }
        if (rule.use) {
            const newUse = Array.isArray(rule.use) ? rule.use : [rule.use];
            const updatedUse = newUse.map(loaderConfig => {
                if (
                    typeof loaderConfig === 'object' &&
                    loaderConfig !== null &&
                    loaderConfig.loader &&
                    (loaderConfig.loader.includes('babel-loader') || loaderConfig.loader.includes('babel-loader/lib'))
                ) {
                    return {
                        ...loaderConfig,
                        options: {
                            ...(loaderConfig.options || {}),
                            cacheDirectory: false,
                        },
                    };
                }
                return loaderConfig;
            });
            return { ...rule, use: Array.isArray(rule.use) ? updatedUse : updatedUse[0] };
        }
        if (rule.loader && (rule.loader.includes('babel-loader') || rule.loader.includes('babel-loader/lib'))) {
            return {
                ...rule,
                options: {
                    ...(rule.options || {}),
                    cacheDirectory: false,
                }
            };
        }
        return rule;
    });
}

const newModuleRules = defaultConfig.module && defaultConfig.module.rules 
    ? disableBabelCache(defaultConfig.module.rules) 
    : [];

module.exports = {
    ...defaultConfig,
    mode: process.env.NODE_ENV === 'production' ? 'production' : 'development',
    devtool: process.env.NODE_ENV === 'production' ? 'source-map' : 'eval',
    stats: {
        ...defaultConfig.stats,
        errorDetails: false, // Optionally hide verbose error details unless needed
    },
    module: {
        ...defaultConfig.module, // Keep other module settings like parser
        rules: newModuleRules,
    },
}; 