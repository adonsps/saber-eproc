var np_team_codes = ['CSGSM', 'TIGS', 'FNBOM', 'MFM', 'PKP', 'PKR'];
var project_team_codes = { "1": "PKR", "2": "PKP", "3": "MFM" };
var combined = {};
if (np_team_codes) {
    np_team_codes.forEach(function(code) {
        combined[code] = { code: code, npteam: code, group: "" };
    });
}
if (project_team_codes) {
    for (var gnum in project_team_codes) {
        var code = project_team_codes[gnum];
        if (!combined[code]) {
            combined[code] = { code: code, npteam: "", group: gnum };
        } else {
            combined[code].group = gnum;
        }
    }
}
console.log(combined);
