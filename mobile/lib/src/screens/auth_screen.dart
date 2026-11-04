import 'package:flutter/material.dart';

import '../api.dart';
import '../app_state.dart';
import '../widgets/common.dart';

/// Sign in or create an account. Pops with true once signed in.
class AuthScreen extends StatefulWidget {
  const AuthScreen({super.key, this.reason});

  final String? reason;

  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  bool _register = false;
  bool _busy = false;
  bool _needsCode = false;
  bool _showPassword = false;
  String? _error;
  Map<String, String> _fieldErrors = const {};

  final _name = TextEditingController();
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _confirm = TextEditingController();
  final _code = TextEditingController();

  @override
  void dispose() {
    for (final c in [_name, _email, _password, _confirm, _code]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    FocusScope.of(context).unfocus();
    final state = AppScope.read(context);
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
    });

    try {
      final Map<String, dynamic> response;
      if (_register) {
        response = await state.api.post('/auth/register', {
          'name': _name.text.trim(),
          'email': _email.text.trim(),
          'password': _password.text,
          'password_confirmation': _confirm.text,
          'device_name': 'Tickora for Android',
        });
      } else {
        response = await state.api.post('/auth/login', {
          'email': _email.text.trim(),
          'password': _password.text,
          if (_needsCode) 'code': _code.text.trim(),
          'device_name': 'Tickora for Android',
        });
      }

      await state.signedInWith(response);
      if (!mounted) return;
      showSnack(
        context,
        _register ? 'Account created. Check your email to confirm it.' : 'Signed in as ${state.user?.name ?? ''}.',
      );
      Navigator.of(context).pop(true);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        if (e.data['two_factor_required'] == true) {
          _needsCode = true;
          _error = e.message;
        } else if (e.fieldErrors.isNotEmpty) {
          _fieldErrors = e.fieldErrors;
          _error = null;
        } else {
          _error = e.message;
        }
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final reason = widget.reason;
    final error = _error;

    return Scaffold(
      appBar: AppBar(title: Text(_register ? 'Create account' : 'Sign in')),
      body: SafeArea(
        child: AutofillGroup(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(24, 8, 24, 24),
            children: [
              if (reason != null) ...[
                Text(reason, style: theme.textTheme.bodyLarge),
                const SizedBox(height: 16),
              ],
              SegmentedButton<bool>(
                segments: const [
                  ButtonSegment(value: false, label: Text('Sign in')),
                  ButtonSegment(value: true, label: Text('Create account')),
                ],
                selected: {_register},
                onSelectionChanged: _busy
                    ? null
                    : (value) => setState(() {
                          _register = value.first;
                          _error = null;
                          _fieldErrors = const {};
                          _needsCode = false;
                        }),
              ),
              const SizedBox(height: 24),
              if (_register) ...[
                TextField(
                  controller: _name,
                  textCapitalization: TextCapitalization.words,
                  textInputAction: TextInputAction.next,
                  autofillHints: const [AutofillHints.name],
                  decoration: InputDecoration(labelText: 'Full name', errorText: _fieldErrors['name']),
                ),
                const SizedBox(height: 14),
              ],
              TextField(
                controller: _email,
                keyboardType: TextInputType.emailAddress,
                autocorrect: false,
                textInputAction: TextInputAction.next,
                autofillHints: const [AutofillHints.email],
                decoration: InputDecoration(labelText: 'Email', errorText: _fieldErrors['email'], errorMaxLines: 3),
              ),
              const SizedBox(height: 14),
              TextField(
                controller: _password,
                obscureText: !_showPassword,
                textInputAction: _register || _needsCode ? TextInputAction.next : TextInputAction.done,
                autofillHints: [_register ? AutofillHints.newPassword : AutofillHints.password],
                onSubmitted: (_) => _register || _needsCode || _busy ? null : _submit(),
                decoration: InputDecoration(
                  labelText: 'Password',
                  errorText: _fieldErrors['password'],
                  errorMaxLines: 4,
                  suffixIcon: IconButton(
                    tooltip: _showPassword ? 'Hide password' : 'Show password',
                    icon: Icon(_showPassword ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                    onPressed: () => setState(() => _showPassword = !_showPassword),
                  ),
                ),
              ),
              if (_register) ...[
                const SizedBox(height: 14),
                TextField(
                  controller: _confirm,
                  obscureText: !_showPassword,
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _busy ? null : _submit(),
                  decoration: const InputDecoration(labelText: 'Confirm password'),
                ),
              ],
              if (_needsCode && !_register) ...[
                const SizedBox(height: 14),
                TextField(
                  controller: _code,
                  keyboardType: TextInputType.number,
                  autofillHints: const [AutofillHints.oneTimeCode],
                  textInputAction: TextInputAction.done,
                  onSubmitted: (_) => _busy ? null : _submit(),
                  decoration: InputDecoration(labelText: 'Authenticator code', errorText: _fieldErrors['code']),
                ),
              ],
              if (error != null) ...[
                const SizedBox(height: 16),
                Text(error, style: TextStyle(color: theme.colorScheme.error, fontWeight: FontWeight.w600)),
              ],
              const SizedBox(height: 24),
              FilledButton(
                onPressed: _busy ? null : _submit,
                child: _busy
                    ? const SizedBox.square(dimension: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                    : Text(_register ? 'Create account' : 'Sign in'),
              ),
              if (!_register) ...[
                const SizedBox(height: 16),
                Text(
                  'Forgot your password? Reset it on the Tickora website, then sign in here.',
                  textAlign: TextAlign.center,
                  style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
