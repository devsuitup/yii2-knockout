ko.extenders.validators = function (target, options) {
	//	default options
	options	=	$.extend({
		abortValidation: false,
		fn:              null,
	}, options);

	console.log('validators', options);

	target.validated = ko.observable(false);
	target.errors    = ko.observableArray([]);
	target.hasError  = ko.computed(function() {
		return target.errors().length > 0;
	}, target);

	target.validate = function() {
		var value    = target();
		var messages = [];
		options.fn(value, messages);
		if (messages.length > 0) {
			target.validated(false);
		} else {
			target.validated(true);
		}
		target.errors(messages);
		console.log('validate', value, messages, target.hasError)
	}

	target.subscribe(function(v) {
		if (!(ko.unwrap(options.abortValidation) || false)) {
			target.validate();
		}
	});

	return target;
}
