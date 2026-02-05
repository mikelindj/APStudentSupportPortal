Component,Referral Table,Action Plan Table,Goal Table
Display Name,ReferralToAP,Action Plan,Goal
Logical Name,crd88_referraltoap,crd88_actionplan,crd88_goal
Schema Name,crd88_ReferralToAP,crd88_ActionPlan,crd88_Goal
Entity Set Name,crd88_referraltoaps,crd88_actionplans,crd88_goals
Primary Key (ID),crd88_referraltoapid,crd88_actionplanid,crd88_goalid
Primary Name Field,crd88_refid,crd88_actionid,crd88_goalid1
Object Type Code,10772,10802,10812


Property,Details
Principal Table (One),crd88_referraltoap
Related Table (Many),crd88_actionplan
Lookup Column Name,crd88_referralid (Typically created on Action Plan)
Behavior,"When a Referral is selected, the app should display all associated Action Plans. Deleting a Referral should ideally restrict or cascade delete associated Plans."


Property,Details
Principal Table (One),crd88_actionplan
Related Table (Many),crd88_goal
Lookup Column Name,crd88_actionplanid_lookup (Typically created on Goal)
Behavior,"Each Goal is a line item belonging to a specific Action Plan. The UI should allow ""Adding a Goal"" directly from the Action Plan view, auto-populating this relationship."

3. Visualizing the Data Hierarchy
This is a Grandparent → Parent → Child structure.
Referral (crd88_referraltoap)└── Action Plan (crd88_actionplan)└── Goal (crd88_goal)


Action plan sample data:
{"@odata.context":"https://acsa.crm5.dynamics.com/api/data/v9.2/$metadata#crd88_actionplans","value":[{"@odata.etag":"W/\"5005395\"","modifiedon":"2026-02-03T01:43:27Z","_owninguser_value":"8541fddf-91fd-f011-8407-000d3aa130f8","crd88_actionby":"me","overriddencreatedon":null,"crd88_actionplanstatus":5,"importsequencenumber":null,"_modifiedonbehalfby_value":null,"statecode":0,"crd88_actionid":"1000","versionnumber":5005395,"utcconversiontimezonecode":null,"_createdonbehalfby_value":null,"crd88_reviewdate":"2026-01-27T00:00:00Z","_modifiedby_value":"8541fddf-91fd-f011-8407-000d3aa130f8","createdon":"2026-02-02T07:19:34Z","_owningbusinessunit_value":"dd87b9a4-2c69-f011-bec2-6045bd1eac83","crd88_actionplanid":"edc09085-0700-f111-8407-000d3aa130f8","crd88_actionplandetails":"this is an action plan","statuscode":1,"_ownerid_value":"8541fddf-91fd-f011-8407-000d3aa130f8","_owningteam_value":null,"_createdby_value":"8541fddf-91fd-f011-8407-000d3aa130f8","timezoneruleversionnumber":4}]}

Goals sample data:
{"@odata.context":"https://acsa.crm5.dynamics.com/api/data/v9.2/$metadata#crd88_goals","value":[{"@odata.etag":"W/\"5005282\"","modifiedon":"2026-02-03T01:37:21Z","_owninguser_value":"8541fddf-91fd-f011-8407-000d3aa130f8","_crd88_actionplan_value":"edc09085-0700-f111-8407-000d3aa130f8","overriddencreatedon":null,"crd88_goaldetails":"first goal","importsequencenumber":null,"_modifiedonbehalfby_value":null,"crd88_completed":true,"statecode":0,"versionnumber":5005282,"utcconversiontimezonecode":null,"crd88_completionnotes":"asdfasdf","crd88_goalid1":"1000","_createdonbehalfby_value":null,"_modifiedby_value":"6c5ee7ca-106e-f011-b4cc-000d3ac7aeb7","createdon":"2026-02-02T07:11:58Z","_owningbusinessunit_value":"dd87b9a4-2c69-f011-bec2-6045bd1eac83","statuscode":1,"_owningteam_value":null,"_createdby_value":"8541fddf-91fd-f011-8407-000d3aa130f8","_ownerid_value":"8541fddf-91fd-f011-8407-000d3aa130f8","crd88_goalid":"1e307673-0600-f111-8407-000d3aa130f8","timezoneruleversionnumber":null}]}

Referral to AP sample data:
{"@odata.context":"https://acsa.crm5.dynamics.com/api/data/v9.2/$metadata#crd88_referraltoaps","value":[{"@odata.etag":"W/\"5002389\"","modifiedon":"2026-02-02T07:11:58Z","_owninguser_value":"8541fddf-91fd-f011-8407-000d3aa130f8","crd88_meetingnotes":"some meeting notes","crd88_strengths":"asdfasdf","crd88_domainsandconditions":"Cognitive:\nHas difficulty understanding oral directions\nMisunderstands material presented at a fast rate","overriddencreatedon":null,"importsequencenumber":null,"crd88_refid":"123123","_modifiedonbehalfby_value":null,"crd88_referralcompleted":false,"statecode":0,"crd88_triggersubjects":"Math","versionnumber":5002389,"utcconversiontimezonecode":null,"crd88_referraltoapid":"2fb72fd8-f6ff-f011-8407-000d3aa130f8","_createdonbehalfby_value":null,"_modifiedby_value":"8541fddf-91fd-f011-8407-000d3aa130f8","_crd88_goals_value":"1e307673-0600-f111-8407-000d3aa130f8","createdon":"2026-02-02T05:20:14Z","_owningbusinessunit_value":"dd87b9a4-2c69-f011-bec2-6045bd1eac83","_crd88_student_value":"c7891aae-6ad6-f011-8544-00224856f021","statuscode":1,"crd88_referralfinaloutcome":null,"_createdby_value":"8541fddf-91fd-f011-8407-000d3aa130f8","_owningteam_value":null,"_ownerid_value":"8541fddf-91fd-f011-8407-000d3aa130f8","timezoneruleversionnumber":null,"crd88_preferredsubjects":"Life Skill"}]}