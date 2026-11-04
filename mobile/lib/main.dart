import 'package:flutter/material.dart';

import 'src/app_state.dart';
import 'src/screens/home_screen.dart';
import 'src/screens/server_screen.dart';
import 'src/theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final state = await AppState.load();
  runApp(TickoraApp(state: state));
}

class TickoraApp extends StatelessWidget {
  const TickoraApp({super.key, required this.state});

  final AppState state;

  @override
  Widget build(BuildContext context) {
    return AppScope(
      state: state,
      child: MaterialApp(
        title: 'Tickora',
        debugShowCheckedModeBanner: false,
        theme: buildTheme(Brightness.light),
        darkTheme: buildTheme(Brightness.dark),
        home: const _Root(),
      ),
    );
  }
}

/// Server setup until a server is chosen, then the app itself.
class _Root extends StatelessWidget {
  const _Root();

  @override
  Widget build(BuildContext context) {
    final state = AppScope.of(context);
    return state.serverUrl == null ? const ServerScreen(firstRun: true) : const HomeScreen();
  }
}
