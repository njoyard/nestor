/*jshint browser:true */
/*global require, define, $, $$ */

define(["ist!tmpl/login", "signals", "rest"], function(template, signals, rest) {
	"use strict";
	
	var currentInput;
	
	function loginKeypress(e) {
		if (e.keyCode === 13 && this.value) {
            var input = $("#password input");

			// Switch to password input
			$("#password").style.display = "block";
			$("#login").style.display = "none";
			
			input.focus();
			currentInput = input;
		}
	}
	
	function passKeypress(e) {
		if (e.keyCode === 13 && this.value) {
			rest.login($("#login input").value, this.value).then(
				function(user) {
					if (!user) {
						login("login failed");
					} else {
						currentInput = null;
						
						// Login successfull
						login.loggedIn.dispatch(user);
					}
				}
			);
		}
	}
	
	function blur(e) {
		// Restore focus
		if (currentInput) {
			setTimeout(function() {
				currentInput.focus();
			}, 50);
		}
	}
	
	// Show login UI
	function login(error) {
		if (!$("#login")) {
			$("#login-container").replaceChild(
				template.render({
					loginKeypress: loginKeypress,
					passKeypress: passKeypress,
					blur: blur
				}),
				$("#loading")
			);
		}

        var input = $("#login input");
		
		$("#login-container").style.display = $("#login").style.display = "block";
		$("#main-container").style.display = $("#password").style.display = "none";
		$("#login .error").innerHTML = error || "";
		
		input.value = $("#password input").value = "";
		input.focus();
		currentInput = input;
	}
	
	login.loggedIn = new signals.Signal();
	
	login.logout = function() {
		rest.logout().then(function() {
			login();
		});
	};
	
	return login;
});