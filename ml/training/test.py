import re

OM_PATTERN = re.compile(r'\[om[_:]\s*([^\]]+)\]', re.IGNORECASE)
SUB_PATTERN = re.compile(r'\[sub:\s*([^>\]]+?)\s*>\s*([^\]]+?)\]', re.IGNORECASE)

test = "Ang [om_matabang] aso ay [sub: tumakbo > tumalon]."
print(OM_PATTERN.findall(test))   # expect: ['matabang']
print(SUB_PATTERN.findall(test))  # expect: [('tumakbo', 'tumalon')]