<div class="card mb-4">
    <div class="card-body">
        <form method="GET" {{ $attributes }}>
            <div class="row g-3 align-items-end">
                @foreach($fields as $field => $config)
                    <div class="col-{{ $config['size'] ?? 'md-3' }}">
                        @if($config['type'] === 'select')
                            <label class="form-label small text-muted">{{ $config['label'] }}</label>
                            <select name="{{ $field }}" class="form-select">
                                <option value="">All {{ $config['label'] }}</option>
                                @foreach($config['options'] ?? [] as $value => $label)
                                    <option value="{{ $value }}" {{ request($field) === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        @elseif($config['type'] === 'date')
                            <label class="form-label small text-muted">{{ $config['label'] }}</label>
                            <input type="date" name="{{ $field }}" class="form-control" value="{{ request($field) }}">
                        @elseif($config['type'] === 'search')
                            <label class="form-label small text-muted">{{ $config['label'] }}</label>
                            <input type="text" name="{{ $field }}" class="form-control" 
                                   placeholder="{{ $config['placeholder'] ?? 'Search...' }}" value="{{ request($field) }}">
                        @endif
                    </div>
                @endforeach
                
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-funnel"></i> Filter
                    </button>
                </div>
                @if(request()->hasAny(array_keys($fields)))
                    <div class="col-md-2">
                        <a href="{{ request()->url() }}" class="btn btn-outline-secondary w-100">Reset</a>
                    </div>
                @endif
            </div>
        </form>
    </div>
</div>

