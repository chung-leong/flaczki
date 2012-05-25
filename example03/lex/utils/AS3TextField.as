package  lex.utils
{
	import flash.display.*
	import flash.events.*;
	import flash.text.*;
	import flash.ui.*;
	import flash.system.*;
	import flash.net.*;
	import flash.utils.*;
	
	public class AS3TextField extends TextField
	{
		private var AS3KeyWords:String = "addEventListener|align|ArgumentError|arguments|Array|as|AS3|Boolean|break|case|catch|class|Class|const|continue|data|Date|decodeURI|decodeURIComponent|default|DefinitionError|delete|do|dynamic|each|else|encodeURI|encodeURIComponent|Error|escape|EvalError|extends|false|finally|flash_proxy|for|function|get|getLineOffset|height|if|implements|import|in|include|index|Infinity|instanceof|interface|internal|intrinsic|is|isFinite|isNaN|isXMLName|label|load|namespace|NaN|native|new|null|Null|object_proxy|override|package|parseFloat|parseInt|private|protected|public|return|set|static|super|switch|this|throw|//trace|true|try|typeof|undefined|unescape|use|var|void|while|with|Accessibility|AccessibilityProperties|ActionScriptVersion|ActivityEvent|AntiAliasType|ApplicationDomain|AsyncErrorEvent|AVM1Movie|BevelFilter|Bitmap|BitmapData|BitmapDataChannel|BitmapFilter|BitmapFilterQuality|BitmapFilterType|BlendMode|BlurFilter|ByteArray|Camera|Capabilities|CapsStyle|ColorMatrixFilter|ColorTransform|ContextMenu|ContextMenuBuiltInItems|ContextMenuEvent|ContextMenuItem|ConvolutionFilter|CSMSettings|DataEvent|Dictionary|DisplacementMapFilter|DisplacementMapFilterMode|DisplayObject|DisplayObjectContainer|DropShadowFilter|Endian|EOFError|ErrorEvent|Event|EventDispatcher|EventPhase|ExternalInterface|FileFilter|FileReference|FileReferenceList|FocusEvent|Font|FontStyle|FontType|FrameLabel|Function|GlowFilter|GradientBevelFilter|GradientGlowFilter|GradientType|Graphics|GridFitType|HTTPStatusEvent|IBitmapDrawable|ID3Info|IDataInput|IDataOutput|IDynamicPropertyOutput|IDynamicPropertyWriter|IEventDispatcher|IExternalizable||IllegalOperationError|IME|IMEConversionMode|IMEEvent|int|InteractiveObject|InterpolationMethod|InvalidSWFError|IOError|IOErrorEvent|JointStyle|Keyboard|KeyboardEvent|KeyLocation|LineScaleMode|Loader|LoaderContext|LoaderInfo|LocalConnection|Math|Matrix|MemoryError|Microphone|MorphShape|Mouse|MouseEvent|MovieClip|Namespace|NetConnection|NetStatusEvent|NetStream|Number|Object|ObjectEncoding|PixelSnapping|Point|PrintJob|PrintJobOptions|PrintJobOrientation|ProgressEvent|Proxy|QName|RangeError|Rectangle|ReferenceError|RegExp|resize|result|Responder|scaleMode|Scene|ScriptTimeoutError|Security|SecurityDomain|SecurityError|SecurityErrorEvent|SecurityPanel|setTextFormat|Shape|SharedObject|SharedObjectFlushStatus|SimpleButton|Socket|Sound|SoundChannel|SoundLoaderContext|SoundMixer|SoundTransform|SpreadMethod|Sprite|StackOverflowError|Stage|stageHeight|stageWidth|StageAlign|StageQuality|StageScaleMode|StaticText|StatusEvent|String|StyleSheet|SWFVersion|SyncEvent|SyntaxError|System|text|TextColorType|TextDisplayMode|TextEvent|TextField|TextFieldAutoSize|TextFieldType|TextFormat|TextFormatAlign|TextLineMetrics|TextRenderer|TextSnapshot|Timer|TimerEvent|Transform|true|TypeError|uint|URIError|URLLoader|URLLoaderDataFormat|URLRequest|URLRequestHeader|URLRequestMethod|URLStream|URLVariables|VerifyError|Video|width|XML|XMLDocument|XMLList|XMLNode|XMLNodeType|XMLSocket",
		 AS3SystemObjects:String = "not_set_yet",
		 KeywordFormat:TextFormat = new TextFormat(null,null,0x0000FF),
		 SystemObjectFormat:TextFormat = new TextFormat(null,null,0xFF0000),
		 BracketFormat:TextFormat = new TextFormat(null,null,0x000000),
		 StringFormat:TextFormat = new TextFormat(null,null,0x009900),
		 CommentFormat:TextFormat = new TextFormat(null,null,0x666666),
		 DefaultFormat:TextFormat = new TextFormat(null,null,0x000000),
		// comment "with a double quote string "
		// comment 'with a single quote string '
		/*  multiline comment 
		accross multiple lines*/
		 SingleQuoteString:RegExp = new RegExp("\'.*\'","g"),
		 DoubleQuoteString:RegExp = new RegExp("\".*\"","g"),
		 StartMultiLineQuote:RegExp = new RegExp("/\\*.*","g"),
		 EndMultiLineQuote:RegExp = new RegExp("\\*/.*","g"),
		 KeyWords:RegExp = new RegExp("(\\b)(" + AS3KeyWords + "){1}(\\.|(\\s)+|;|,|\\(|\\)|\\]|\\[|\\{|\\}){1}","g"),
		 SystemObjects:RegExp = new RegExp("(\\b)(" + AS3SystemObjects + "){1}(\\.|(\\s)+|;|,|\\(|\\)|\\]|\\[|\\{|\\}){1}","g"),
		 Comment:RegExp = new RegExp("//.*", "g"),
		 myTab:RegExp = new RegExp(">>","g"),
		 Brackets:RegExp = new RegExp("(\\{|\\[|\\(|\\}|\\]|\\))","g"),
		 txtCode:TextField;
		public var format:TextFormat;

		public function AS3TextField(_str:String = ''):void{
			txtCode = this;
			autoSize = TextFieldAutoSize.LEFT;
			format = new TextFormat();
			format.font="Courier New";
			format.size=12;
			setText(_str);
		}

		public function setText(_str:String):void{
			txtCode.text = _str;
			HighlightSyntax();
			setTextFormat(format);
		}


		private function HighlightSyntax():void{
			var InMultilineComment:Boolean = false;
			var i:int;
			for(i=0;i<txtCode.numLines;i++){
				if(InMultilineComment){
					txtCode.setTextFormat(CommentFormat,txtCode.getLineOffset(i),txtCode.getLineOffset(i)+txtCode.getLineText(i).length);
					InMultilineComment = !ParseExpression(EndMultiLineQuote,CommentFormat,i,false);
				} else {
					var CommentIndex:Boolean;
					ParseExpression(KeyWords,KeywordFormat,i,true);
					ParseExpression(SystemObjects,SystemObjectFormat,i,true);
					ParseExpression(Brackets,BracketFormat,i,false);
					ParseExpression(SingleQuoteString,StringFormat,i,false);
					ParseExpression(DoubleQuoteString,StringFormat,i,false);
					CommentIndex = ParseExpression(Comment,CommentFormat,i,false,true);
					InMultilineComment = ParseExpression(StartMultiLineQuote,CommentFormat,i,false,true);
					if(InMultilineComment){InMultilineComment = !ParseExpression(EndMultiLineQuote,CommentFormat,i,false,true);}
				}
			}
		}

		private function ParseExpression(exp:RegExp,format:TextFormat,lineno:Number,Trim:Boolean,DontSearchStrings:Boolean=false):Boolean{
			var result:Object = exp.exec(txtCode.getLineText(lineno))
			if (result == null) {return false};
			while (result != null) {
				if(DontSearchStrings){
					var IsInString:Boolean = false;
					if(InString(result,DoubleQuoteString,lineno) == true){IsInString = true}
					if(InString(result,SingleQuoteString,lineno) == true){IsInString = true}
					if(IsInString){return false}
				}
				if(Trim){
					txtCode.setTextFormat(format,txtCode.getLineOffset(lineno) + result.index,txtCode.getLineOffset(lineno) + result.index+result[0].length - 1);
				} else {
					txtCode.setTextFormat(format,txtCode.getLineOffset(lineno) + result.index,txtCode.getLineOffset(lineno) + result.index+result[0].length);
				}
				result = exp.exec(txtCode.getLineText(lineno));
			}
			return true;
		}

		private function InString(result:Object,exp:RegExp,lineno:Number):Boolean{
			var stringResult:Object = exp.exec(txtCode.getLineText(lineno))
			var IsInString:Boolean = false;
			while (stringResult != null) {
				if(result.index > stringResult.index && result.index < stringResult.index + stringResult[0].length){
					IsInString = true;
				}
				stringResult = exp.exec(txtCode.getLineText(lineno))
			}
			return IsInString
		}


	}
}