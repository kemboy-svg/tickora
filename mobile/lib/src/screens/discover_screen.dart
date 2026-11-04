import 'dart:async';

import 'package:flutter/material.dart';

import '../api.dart';
import '../app_state.dart';
import '../models.dart';
import '../widgets/common.dart';
import '../widgets/event_card.dart';
import 'event_screen.dart';

const _whens = <String, String>{
  '': 'Any time',
  'today': 'Today',
  'weekend': 'This weekend',
  'week': 'Next 7 days',
  'month': 'Next 30 days',
};

/// Browse and search upcoming events.
class DiscoverScreen extends StatefulWidget {
  const DiscoverScreen({super.key});

  @override
  State<DiscoverScreen> createState() => _DiscoverScreenState();
}

class _DiscoverScreenState extends State<DiscoverScreen> {
  final _search = TextEditingController();
  final _scroll = ScrollController();
  Timer? _debounce;

  List<Category> _categories = const [];
  final List<EventCard> _events = [];
  String _category = '';
  String _when = '';
  int _page = 0;
  int _lastPage = 1;
  int _total = 0;
  bool _loading = false;
  ApiException? _error;
  int _generation = 0;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_scroll.position.pixels > _scroll.position.maxScrollExtent - 600) _loadMore();
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadCategories();
      _reload();
    });
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _search.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _loadCategories() async {
    try {
      final response = await AppScope.read(context).api.get('/categories');
      final list = response['data'];
      if (!mounted || list is! List) return;
      setState(() => _categories = list.whereType<Map<String, dynamic>>().map(Category.fromJson).toList());
    } on ApiException {
      // Filters are optional; the list still works.
    }
  }

  Future<void> _reload() async {
    _generation++;
    setState(() {
      _events.clear();
      _page = 0;
      _lastPage = 1;
      _error = null;
      _loading = false;
    });
    await _loadMore();
  }

  Future<void> _loadMore() async {
    if (_loading || _page >= _lastPage) return;
    final generation = _generation;
    setState(() => _loading = true);

    try {
      final response = await AppScope.read(context).api.get('/events', query: {
        'page': '${_page + 1}',
        if (_search.text.trim().isNotEmpty) 'q': _search.text.trim(),
        if (_category.isNotEmpty) 'category': _category,
        if (_when.isNotEmpty) 'when': _when,
      });
      if (!mounted || generation != _generation) return;

      final data = response['data'];
      final meta = response['meta'] is Map<String, dynamic> ? response['meta'] as Map<String, dynamic> : const {};
      setState(() {
        if (data is List) _events.addAll(data.whereType<Map<String, dynamic>>().map(EventCard.fromJson));
        _page = meta['current_page'] is int ? meta['current_page'] as int : _page + 1;
        _lastPage = meta['last_page'] is int ? meta['last_page'] as int : _page;
        _total = meta['total'] is int ? meta['total'] as int : _events.length;
        _error = null;
      });
    } on ApiException catch (e) {
      if (mounted && generation == _generation) setState(() => _error = e);
    } finally {
      if (mounted && generation == _generation) setState(() => _loading = false);
    }
  }

  void _onSearchChanged(String _) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 450), _reload);
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        bottom: false,
        child: RefreshIndicator(
          onRefresh: _reload,
          child: CustomScrollView(
            controller: _scroll,
            physics: const AlwaysScrollableScrollPhysics(),
            slivers: [
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 4),
                  child: Row(
                    children: [
                      const TickoraLogo(size: 30),
                      const Spacer(),
                      if (_total > 0)
                        Text('$_total events',
                            style: theme.textTheme.labelLarge?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
                    ],
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 12, 20, 8),
                  child: TextField(
                    controller: _search,
                    onChanged: _onSearchChanged,
                    textInputAction: TextInputAction.search,
                    onSubmitted: (_) => _reload(),
                    decoration: InputDecoration(
                      hintText: 'Search events, venues…',
                      prefixIcon: const Icon(Icons.search),
                      suffixIcon: _search.text.isEmpty
                          ? null
                          : IconButton(
                              tooltip: 'Clear search',
                              icon: const Icon(Icons.close),
                              onPressed: () {
                                _search.clear();
                                _reload();
                              },
                            ),
                    ),
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: SizedBox(
                  height: 48,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(horizontal: 20),
                    children: [
                      for (final entry in _whens.entries)
                        Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: ChoiceChip(
                            label: Text(entry.value),
                            selected: _when == entry.key,
                            onSelected: (_) {
                              setState(() => _when = entry.key);
                              _reload();
                            },
                          ),
                        ),
                    ],
                  ),
                ),
              ),
              if (_categories.isNotEmpty)
                SliverToBoxAdapter(
                  child: SizedBox(
                    height: 48,
                    child: ListView(
                      scrollDirection: Axis.horizontal,
                      padding: const EdgeInsets.symmetric(horizontal: 20),
                      children: [
                        Padding(
                          padding: const EdgeInsets.only(right: 8),
                          child: FilterChip(
                            label: const Text('All'),
                            selected: _category.isEmpty,
                            onSelected: (_) {
                              setState(() => _category = '');
                              _reload();
                            },
                          ),
                        ),
                        for (final category in _categories)
                          Padding(
                            padding: const EdgeInsets.only(right: 8),
                            child: FilterChip(
                              label: Text(category.name),
                              selected: _category == category.slug,
                              onSelected: (_) {
                                setState(() => _category = _category == category.slug ? '' : category.slug);
                                _reload();
                              },
                            ),
                          ),
                      ],
                    ),
                  ),
                ),
              ..._content(),
              const SliverToBoxAdapter(child: SizedBox(height: 24)),
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _content() {
    final error = _error;
    if (_events.isEmpty && error != null) {
      return [
        SliverFillRemaining(
          hasScrollBody: false,
          child: MessageView(
            icon: error.isNetwork ? Icons.wifi_off : Icons.error_outline,
            title: error.isNetwork ? "You're offline" : "Couldn't load events",
            message: error.message,
            actionLabel: 'Try again',
            onAction: _reload,
          ),
        ),
      ];
    }

    if (_events.isEmpty && !_loading && _page > 0) {
      return [
        const SliverFillRemaining(
          hasScrollBody: false,
          child: MessageView(
            icon: Icons.event_busy_outlined,
            title: 'No events found',
            message: 'Try another search or filter.',
          ),
        ),
      ];
    }

    return [
      SliverPadding(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
        sliver: SliverList.separated(
          itemCount: _events.length,
          separatorBuilder: (context, index) => const SizedBox(height: 16),
          itemBuilder: (context, index) {
            final event = _events[index];
            return EventCardView(
              event: event,
              onTap: () => Navigator.of(context).push(
                MaterialPageRoute<void>(builder: (_) => EventScreen(slug: event.slug, preview: event)),
              ),
            );
          },
        ),
      ),
      if (_loading)
        const SliverToBoxAdapter(
          child: Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
        ),
    ];
  }
}
