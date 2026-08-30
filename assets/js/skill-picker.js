/**
 * SkillPicker — search-as-you-type skill selector.
 *
 * Usage:
 *   <div class="skill-picker"
 *        data-endpoint="/Internship/includes/skill-search.php"
 *        data-input-name="skills"
 *        data-show-proficiency="true"
 *        data-show-importance="false">
 *   </div>
 *
 * data-show-proficiency: show beginner/intermediate/advanced per skill (for student profiles)
 * data-show-importance:  show required/preferred + minimum level (for offer posting)
 */

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.skill-picker').forEach(initPicker);
});

function initPicker(container) {
    var endpoint = container.dataset.endpoint;
    var inputName = container.dataset.inputName || 'skills';
    var showProf = container.dataset.showProficiency === 'true';
    var showImp = container.dataset.showImportance === 'true';

    // Build the search input
    var searchBox = document.createElement('input');
    searchBox.type = 'text';
    searchBox.placeholder = 'Type to search skills...';
    searchBox.className = 'skill-search-input';
    searchBox.autocomplete = 'off';

    var dropdown = document.createElement('div');
    dropdown.className = 'skill-dropdown';
    dropdown.style.display = 'none';

    var selectedList = document.createElement('div');
    selectedList.className = 'skill-selected-list';

    container.appendChild(searchBox);
    container.appendChild(dropdown);
    container.appendChild(selectedList);

    // Load any pre-existing skills (edit mode)
    var existing = container.dataset.existing;
    if (existing) {
        try {
            JSON.parse(existing).forEach(function (s) {
                addSkillChip(s);
            });
        } catch (e) {}
    }

    var debounceTimer = null;

    searchBox.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var q = searchBox.value.trim();
        if (q.length < 1) {
            dropdown.style.display = 'none';
            return;
        }
        debounceTimer = setTimeout(function () {
            fetch(endpoint + '?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (results) {
                    dropdown.innerHTML = '';
                    if (results.length === 0) {
                        dropdown.innerHTML = '<div class="skill-dropdown-empty">No skills found</div>';
                        dropdown.style.display = 'block';
                        return;
                    }
                    results.forEach(function (skill) {
                        // skip if already selected
                        if (selectedList.querySelector('[data-skill-id="' + skill.id + '"]')) return;

                        var item = document.createElement('div');
                        item.className = 'skill-dropdown-item';
                        item.textContent = skill.name;

                        var cat = document.createElement('span');
                        cat.className = 'skill-dropdown-cat';
                        cat.textContent = skill.category;
                        item.appendChild(cat);

                        item.addEventListener('click', function () {
                            addSkillChip({ id: skill.id, name: skill.name });
                            dropdown.style.display = 'none';
                            searchBox.value = '';
                        });
                        dropdown.appendChild(item);
                    });
                    dropdown.style.display = 'block';
                });
        }, 200);
    });

    // Close dropdown on outside click
    document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    function addSkillChip(skill) {
        var chip = document.createElement('div');
        chip.className = 'skill-chip';
        chip.dataset.skillId = skill.id;

        var label = document.createElement('span');
        label.className = 'skill-chip-name';
        label.textContent = skill.name;
        chip.appendChild(label);

        // Hidden input for skill ID
        var hiddenId = document.createElement('input');
        hiddenId.type = 'hidden';
        hiddenId.name = inputName + '[' + skill.id + '][id]';
        hiddenId.value = skill.id;
        chip.appendChild(hiddenId);

        if (showProf) {
            var profSelect = document.createElement('select');
            profSelect.name = inputName + '[' + skill.id + '][proficiency]';
            profSelect.className = 'skill-chip-select';
            ['beginner', 'intermediate', 'advanced'].forEach(function (lvl) {
                var opt = document.createElement('option');
                opt.value = lvl;
                opt.textContent = lvl.charAt(0).toUpperCase() + lvl.slice(1);
                if (skill.proficiency && skill.proficiency === lvl) opt.selected = true;
                profSelect.appendChild(opt);
            });
            chip.appendChild(profSelect);
        }

        if (showImp) {
            var impSelect = document.createElement('select');
            impSelect.name = inputName + '[' + skill.id + '][importance]';
            impSelect.className = 'skill-chip-select';
            ['required', 'preferred'].forEach(function (v) {
                var opt = document.createElement('option');
                opt.value = v;
                opt.textContent = v.charAt(0).toUpperCase() + v.slice(1);
                if (skill.importance && skill.importance === v) opt.selected = true;
                impSelect.appendChild(opt);
            });
            chip.appendChild(impSelect);

            var minProfSelect = document.createElement('select');
            minProfSelect.name = inputName + '[' + skill.id + '][minimum_proficiency]';
            minProfSelect.className = 'skill-chip-select';
            ['beginner', 'intermediate', 'advanced'].forEach(function (lvl) {
                var opt = document.createElement('option');
                opt.value = lvl;
                opt.textContent = 'Min: ' + lvl.charAt(0).toUpperCase() + lvl.slice(1);
                if (skill.minimum_proficiency && skill.minimum_proficiency === lvl) opt.selected = true;
                minProfSelect.appendChild(opt);
            });
            chip.appendChild(minProfSelect);
        }

        // Remove button
        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'skill-chip-remove';
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', function () {
            chip.remove();
        });
        chip.appendChild(removeBtn);

        selectedList.appendChild(chip);
    }
}