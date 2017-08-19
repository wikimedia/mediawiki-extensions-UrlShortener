/*jshint node:true */
module.exports = function ( grunt ) {
	grunt.loadNpmTasks( 'grunt-contrib-jshint' );
	grunt.loadNpmTasks( 'grunt-jsonlint' );
	grunt.loadNpmTasks( 'grunt-banana-checker' );
	grunt.loadNpmTasks( 'grunt-jscs' );
	grunt.loadNpmTasks( 'grunt-tyops' );
	grunt.loadNpmTasks( 'grunt-stylelint' );

	var conf = grunt.file.readJSON( 'extension.json' );
	grunt.initConfig( {
		tyops: {
			options: {
				typos: 'build/typos.json'
			},
			src: [
				'**/*',
				'!{node_modules,vendor}/**',
				'!build/typos.json'
			]
		},
		jshint: {
			options: {
				jshintrc: true
			},
			all: [
				'modules/**/*.js'
			]
		},
		jscs: {
			src: '<%= jshint.all %>'
		},
		banana: conf.MessagesDirs,
		jsonlint: {
			all: [
				'**/*.json',
				'!node_modules/**',
				'!vendor/**'
			]
		},
		stylelint: {
			all: [
				'**/*.less',
				'!node_modules/**',
				'!vendor/**'
			]
		}
	} );

	grunt.registerTask( 'test', [ 'tyops', 'jshint', 'jscs', 'jsonlint', 'banana', 'stylelint' ] );
	grunt.registerTask( 'default', 'test' );
};
