import 'package:flutter/material.dart';

import 'account_screen.dart';
import 'discover_screen.dart';
import 'tickets_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  /// The selected tab. Screens pushed on top (checkout, for example) set it
  /// to send the buyer to My tickets.
  static final tab = ValueNotifier<int>(0);

  static void showTickets() {
    tab.value = 1;
    ticketsChanged.value++;
  }

  /// Bumped when tickets were bought, so My tickets reloads.
  static final ticketsChanged = ValueNotifier<int>(0);

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<int>(
      valueListenable: HomeScreen.tab,
      builder: (context, index, _) => Scaffold(
        body: IndexedStack(
          index: index,
          children: const [
            DiscoverScreen(),
            TicketsScreen(),
            AccountScreen(),
          ],
        ),
        bottomNavigationBar: NavigationBar(
          selectedIndex: index,
          onDestinationSelected: (i) {
            HomeScreen.tab.value = i;
            if (i == 1) HomeScreen.ticketsChanged.value++;
          },
          destinations: const [
            NavigationDestination(
                icon: Icon(Icons.explore_outlined), selectedIcon: Icon(Icons.explore), label: 'Discover'),
            NavigationDestination(
                icon: Icon(Icons.confirmation_number_outlined),
                selectedIcon: Icon(Icons.confirmation_number),
                label: 'My tickets'),
            NavigationDestination(
                icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'Account'),
          ],
        ),
      ),
    );
  }
}
